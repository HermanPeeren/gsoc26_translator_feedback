<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Translation.claude
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Translation\Claude\Extension;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Translations\Administrator\Event\TranslateEvent;
use Joomla\Event\SubscriberInterface;
use Joomla\Http\Http;

/**
 * Translation provider plugin that translates an item's strings with the Anthropic
 * Claude API. It answers the component's onTranslate event, returning the translated
 * strings or throwing so a failure reaches the user rather than passing silently.
 *
 * @since  0.4.0
 */
final class Claude extends CMSPlugin implements SubscriberInterface
{
    /**
     * Load the plugin language files automatically.
     *
     * @var    boolean
     * @since  0.4.0
     */
    protected $autoloadLanguage = true;

    /**
     * The Anthropic Messages API endpoint.
     *
     * @var    string
     * @since  0.4.0
     */
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    /**
     * The Anthropic API version, sent as the anthropic-version header.
     *
     * @var    string
     * @since  0.4.0
     */
    private const ANTHROPIC_VERSION = '2023-06-01';

    /**
     * Upper bound on the response length, large enough for a long item's translation.
     *
     * @var    integer
     * @since  0.4.0
     */
    private const MAX_TOKENS = 16000;

    /**
     * Seconds to wait for the API when the plugin sets none, generous because translating a
     * long item takes a while.
     *
     * @var    integer
     * @since  1.2.0
     */
    private const DEFAULT_TIMEOUT = 300;

    /**
     * The effort levels a request may ask for. Lower effort means less thinking, which is
     * cheaper and faster; measured on language strings it did not change the translations.
     *
     * @var    string[]
     * @since  1.2.0
     */
    private const EFFORTS = ['low', 'medium', 'high'];

    /**
     * The HTTP client used to call the API.
     *
     * @var    Http
     * @since  0.4.0
     */
    private Http $http;

    /**
     * Constructor.
     *
     * @param   array  $config  The plugin configuration.
     * @param   Http   $http    The HTTP client used to call the API.
     *
     * @since   0.4.0
     */
    public function __construct($config, Http $http)
    {
        parent::__construct($config);

        $this->http = $http;
    }

    /**
     * Returns the events this subscriber listens to.
     *
     * @return  array
     *
     * @since   0.4.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onTranslate' => 'onTranslate',
        ];
    }

    /**
     * Translate an item's strings with the Claude API.
     *
     * Reads the strings and languages from the event and adds the translation for the
     * component. A missing key or any API failure throws, so the reason reaches the user.
     *
     * @param   TranslateEvent  $event  The translate event.
     *
     * @return  void
     *
     * @throws  \RuntimeException  When the key is missing or the API call fails.
     *
     * @since   0.4.0
     */
    public function onTranslate(TranslateEvent $event): void
    {
        $apiKey = trim((string) $this->params->get('api_key', ''));

        if ($apiKey === '') {
            throw new \RuntimeException('No Claude API key is configured for the translation plugin.');
        }

        $translated = $this->requestTranslation(
            $event->getSourceStrings(),
            $event->getSourceLanguage(),
            $event->getTargetLanguage(),
            $event->getRules(),
            $apiKey
        );

        $event->addResult($translated);

        // One provider answers per item; stop the rest of the group running.
        $event->stopPropagation();
    }

    /**
     * Ask the Claude API to translate the strings and return them keyed as given.
     *
     * The whole collection is sent as one JSON object so the model keeps the context
     * between an item's strings. Each string goes out numbered, with its key alongside as
     * context, and the reply maps each number to the translation: a long key such as a
     * language constant is then not repeated in the reply, which is the part paid for most.
     *
     * @param   array   $strings         The source strings keyed by field.
     * @param   string  $sourceLanguage  The source language code.
     * @param   string  $targetLanguage  The target language code.
     * @param   array   $rules           The distilled rules to steer the translation, grouped by rule type.
     * @param   string  $apiKey          The Anthropic API key.
     *
     * @return  array  The translated strings keyed by field.
     *
     * @throws  \RuntimeException  When the API cannot be reached or returns an error.
     *
     * @since   0.4.0
     */
    private function requestTranslation(
        array $strings,
        string $sourceLanguage,
        string $targetLanguage,
        array $rules,
        string $apiKey
    ): array {
        $model   = (string) $this->params->get('model', 'claude-sonnet-5');
        $numbers = array_map('strval', range(1, \count($strings)));
        $payload = [
            'model'         => $model,
            'max_tokens'    => self::MAX_TOKENS,
            'system'        => $this->systemPrompt($sourceLanguage, $targetLanguage, $rules),
            'messages'      => [
                [
                    'role'    => 'user',
                    'content' => json_encode($this->numberedStrings($strings), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
            ],
            // Constrain the reply to a JSON object with exactly these numbers as keys, so a long
            // HTML value can never come back as prose or malformed JSON the decoder would reject.
            'output_config' => self::outputConfigFor(
                $this->responseFormat($numbers),
                $model,
                (string) $this->params->get('effort', 'low')
            ),
        ];

        return $this->keyedTranslation(
            $this->parseTranslation($this->callApi($payload, $apiKey), $numbers),
            array_keys($strings)
        );
    }

    /**
     * Number the strings for the request, keeping each one's key alongside as context.
     *
     * @param   array  $strings  The source strings keyed by field.
     *
     * @return  array  Each string as its key and text, keyed by its number from 1.
     *
     * @since   1.2.0
     */
    private function numberedStrings(array $strings): array
    {
        $numbered = [];
        $number   = 0;

        foreach ($strings as $key => $text) {
            $numbered[(string) ++$number] = ['key' => (string) $key, 'text' => $text];
        }

        return $numbered;
    }

    /**
     * Put the translations back under the keys the strings were sent with.
     *
     * @param   array  $byNumber  The translations keyed by number, for the numbers answered.
     * @param   array  $keys      The keys the strings were sent with, in the order they were numbered.
     *
     * @return  array  The translations keyed by the caller's keys.
     *
     * @since   1.2.0
     */
    private function keyedTranslation(array $byNumber, array $keys): array
    {
        $translated = [];

        foreach (array_values($keys) as $index => $key) {
            if (isset($byNumber[$index + 1])) {
                $translated[$key] = $byNumber[$index + 1];
            }
        }

        return $translated;
    }

    /**
     * The output settings for a request: the reply format, and the effort when the model takes one.
     *
     * Haiku does not accept an effort level, so it is left out for that model.
     *
     * @param   array   $format  The structured-output format.
     * @param   string  $model   The model the request goes to.
     * @param   string  $effort  The effort level the plugin is set to.
     *
     * @return  array  The output_config of the request.
     *
     * @since   1.2.0
     */
    private static function outputConfigFor(array $format, string $model, string $effort): array
    {
        $config = ['format' => $format];

        if (\in_array($effort, self::EFFORTS, true) && !str_starts_with($model, 'claude-haiku')) {
            $config['effort'] = $effort;
        }

        return $config;
    }

    /**
     * Post a request to the Claude API and return the response body, throwing on failure.
     *
     * @param   array   $payload  The request payload.
     * @param   string  $apiKey   The Anthropic API key.
     *
     * @return  string  The response body.
     *
     * @throws  \RuntimeException  When the API cannot be reached or returns an error.
     *
     * @since   0.4.0
     */
    private function callApi(array $payload, string $apiKey): string
    {
        $headers = [
            'x-api-key'         => $apiKey,
            'anthropic-version' => self::ANTHROPIC_VERSION,
            'content-type'      => 'application/json',
        ];

        try {
            $response = $this->http->post(
                self::ENDPOINT,
                json_encode($payload),
                $headers,
                max(10, (int) $this->params->get('timeout', self::DEFAULT_TIMEOUT))
            );
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('Could not reach the Claude API: ' . $e->getMessage(), 0, $e);
        }

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException($this->errorMessage($response->getStatusCode(), (string) $response->getBody()));
        }

        return (string) $response->getBody();
    }

    /**
     * Build a readable message from an error response, preferring the API's own message.
     *
     * @param   integer  $status  The HTTP status code.
     * @param   string   $body    The API response body.
     *
     * @return  string
     *
     * @since   0.4.0
     */
    private function errorMessage(int $status, string $body): string
    {
        $decoded = json_decode($body, true);
        $message = $body;

        if (\is_array($decoded) && isset($decoded['error']['message']) && \is_string($decoded['error']['message'])) {
            $message = $decoded['error']['message'];
        }

        return \sprintf('Claude API error (HTTP %d): %s', $status, $message);
    }

    /**
     * Build the system prompt that tells the model how to translate.
     *
     * @param   string  $sourceLanguage  The source language code.
     * @param   string  $targetLanguage  The target language code.
     * @param   array   $rules           The distilled rules to steer the translation, grouped by rule type.
     *
     * @return  string
     *
     * @since   0.4.0
     */
    private function systemPrompt(string $sourceLanguage, string $targetLanguage, array $rules): string
    {
        $prompt = \sprintf(
            'You are a professional translator. You receive a JSON object of numbered strings to translate from'
            . ' %s to %s. Each entry has a "key", which only tells you what the string is for and is never'
            . ' translated, and a "text" to translate. Translate only the human-readable text; preserve HTML tags,'
            . ' placeholders and entities exactly. Respond with only a JSON object that maps each number to its'
            . ' translated text, and nothing else.',
            $sourceLanguage,
            $targetLanguage
        );

        $guidance = $this->ruleGuidance($rules);

        return $guidance === '' ? $prompt : $prompt . "\n\n" . $guidance;
    }

    /**
     * Build the guidance from the distilled rules, or an empty string when there are none.
     *
     * The rules come from earlier human corrections, given as soft guidance so the model still
     * produces natural grammar and agreement: a terminology glossary, style notes, and terms to
     * leave untranslated.
     *
     * @param   array  $rules  The distilled rules, grouped by rule type.
     *
     * @return  string
     *
     * @since   0.7.0
     */
    private function ruleGuidance(array $rules): string
    {
        $sections = [];

        $terminology = [];

        foreach ($rules['terminology'] ?? [] as $rule) {
            $source = trim((string) ($rule['source_term'] ?? ''));
            $target = trim((string) ($rule['target_term'] ?? ''));
            $text   = trim((string) ($rule['rule_text'] ?? ''));

            if ($source !== '' && $target !== '') {
                $terminology[] = \sprintf('- "%s" -> "%s"', $source, $target);
            } elseif ($text !== '') {
                $terminology[] = '- ' . $text;
            }
        }

        if ($terminology !== []) {
            $sections[] = "TERMINOLOGY (translate these terms this way):\n" . implode("\n", $terminology);
        }

        $style = [];

        foreach ($rules['style'] ?? [] as $rule) {
            $text = trim((string) ($rule['rule_text'] ?? ''));

            if ($text !== '') {
                $style[] = '- ' . $text;
            }
        }

        if ($style !== []) {
            $sections[] = "STYLE:\n" . implode("\n", $style);
        }

        $preservation = [];

        foreach ($rules['preservation'] ?? [] as $rule) {
            $term = trim((string) ($rule['source_term'] ?? ''));
            $text = trim((string) ($rule['rule_text'] ?? ''));

            if ($term !== '') {
                $preservation[] = '- ' . $term;
            } elseif ($text !== '') {
                $preservation[] = '- ' . $text;
            }
        }

        if ($preservation !== []) {
            $sections[] = "PRESERVE (keep these unchanged):\n" . implode("\n", $preservation);
        }

        if ($sections === []) {
            return '';
        }

        return 'Apply these conventions from earlier corrections where they fit the meaning; keep natural grammar'
            . " and agreement in the target language.\n\n" . implode("\n\n", $sections);
    }

    /**
     * Build the structured-output format that pins the reply to a JSON object with
     * exactly the given string keys. The API then constrains the model to valid,
     * schema-matching JSON, so a long HTML value cannot return as prose or with the
     * broken escaping (stray quotes or newlines) that a free-form reply can carry.
     *
     * @param   array  $keys  The field keys expected back.
     *
     * @return  array
     *
     * @since   0.4.0
     */
    private function responseFormat(array $keys): array
    {
        $properties = [];

        foreach ($keys as $key) {
            $properties[$key] = ['type' => 'string'];
        }

        return [
            'type'   => 'json_schema',
            'schema' => [
                'type'                 => 'object',
                'properties'           => $properties,
                'required'             => $keys,
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * Pull the translated strings out of the API response.
     *
     * Reads the assistant's text, decodes the JSON object it holds, and keeps only the
     * expected keys with string values. Throws when the response holds no usable translation.
     *
     * @param   string  $body          The API response body.
     * @param   array   $expectedKeys  The field keys that were sent for translation.
     *
     * @return  array  The translated strings keyed by field.
     *
     * @throws  \RuntimeException  When the response holds no usable translation.
     *
     * @since   0.4.0
     */
    private function parseTranslation(string $body, array $expectedKeys): array
    {
        $response = json_decode($body, true);

        if (($response['stop_reason'] ?? '') === 'refusal') {
            throw new \RuntimeException('The Claude API refused the translation request.');
        }

        if (($response['stop_reason'] ?? '') === 'max_tokens') {
            throw new \RuntimeException('The Claude API response was cut off because the item is too long to translate in one request.');
        }

        $text = '';

        foreach ((array) ($response['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text = (string) ($block['text'] ?? '');

                break;
            }
        }

        $translated = json_decode(trim($text), true);

        if (!\is_array($translated)) {
            throw new \RuntimeException('The Claude API returned an unreadable translation.');
        }

        // Keep only the strings asked for, so an unexpected key never reaches the draft.
        $strings = [];

        foreach ($expectedKeys as $key) {
            if (isset($translated[$key]) && \is_string($translated[$key])) {
                $strings[$key] = $translated[$key];
            }
        }

        if ($strings === []) {
            throw new \RuntimeException('The Claude API returned no usable translation.');
        }

        return $strings;
    }
}
