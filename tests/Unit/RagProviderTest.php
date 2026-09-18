<?php

/**
 * @package     Joomla.Tests
 * @subpackage  plg_rag_claude
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Joomla\Component\Translations\Tests\Unit;

use Joomla\Plugin\Rag\Claude\Extension\Claude;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * What the RAG plugin accepts back from the API.
 *
 * The rules this reads become the guidance every later translation is steered by, and the
 * standard forms it reads are written to a table that is never asked again. Both are read
 * out of a language model's reply, so what the reader refuses matters more here than in a
 * call whose answer is used once and forgotten.
 *
 * @since  1.0.1
 */
final class RagProviderTest extends TestCase
{
    /**
     * Distilled rules are handed on as the API gave them, for the component to check.
     *
     * The plugin is the provider, not the reviewer: which candidates become rules, and
     * whether a candidate refines an existing rule, is the component's decision.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testDistilledRulesAreHandedOnAsGiven(): void
    {
        $rules = [
            [
                'id'                  => 0,
                'rule_type'           => 'terminology',
                'rule_name'           => 'module',
                'rule_text'           => 'Translate "module" as "onderdeel"',
                'source_term'         => 'module',
                'target_term'         => 'onderdeel',
                'search_keywords'     => 'module position',
                'confidence'          => 0.9,
                'source_feedback_ids' => [12, 15],
            ],
        ];

        $this->assertSame($rules, self::call('parseDistillation', [self::reply(['rules' => $rules])]));
    }

    /**
     * A batch the model found nothing reusable in distils to no rules, not to an error.
     *
     * Most batches of corrections are typos and one-off edits, so this is the ordinary case.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testABatchWithNothingReusableIsNotAFailure(): void
    {
        $this->assertSame([], self::call('parseDistillation', [self::reply(['rules' => []])]));
    }

    /**
     * Standard forms are read back keyed by the word they were asked about.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testStandardFormsAreReadBackKeyedByWord(): void
    {
        $forms = self::call(
            'parseNormalisation',
            [
                self::reply(
                    [
                        'words' => [
                            ['word' => 'artikelen', 'standard_form' => 'artikel'],
                            ['word' => 'modules', 'standard_form' => 'module'],
                        ],
                    ]
                ),
            ]
        );

        $this->assertSame(['artikelen' => 'artikel', 'modules' => 'module'], $forms);
    }

    /**
     * A pair the model returned incompletely resolves to nothing rather than to a wrong form.
     *
     * A pair without a word cannot be keyed, and a word without a form is not reduced. The
     * component drops both before storing them, so an empty form here costs recall on that
     * word and nothing else.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testAnIncompletePairResolvesToNothing(): void
    {
        $forms = self::call(
            'parseNormalisation',
            [
                self::reply(
                    [
                        'words' => [
                            ['word' => 'artikelen', 'standard_form' => 'artikel'],
                            ['word' => '', 'standard_form' => 'module'],
                            ['word' => 'plugins'],
                            'not a pair at all',
                        ],
                    ]
                ),
            ]
        );

        $this->assertSame(['artikelen' => 'artikel', 'plugins' => ''], $forms);
    }

    /**
     * A reply that is not what was asked for is refused, and names what was asked for.
     *
     * Distilling and reducing words are two different calls that fail for the same reasons,
     * and the error is the only thing that says which one it was.
     *
     * @param   string  $method    The reader to run.
     * @param   string  $body      The reply to read.
     * @param   string  $expected  Text the error has to carry.
     *
     * @return  void
     *
     * @dataProvider  unusableReplies
     *
     * @since   1.0.1
     */
    public function testAReplyThatIsNotAnAnswerIsRefused(string $method, string $body, string $expected): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($expected);

        self::call($method, [$body]);
    }

    /**
     * Replies that must not be acted on, with what each failure has to say.
     *
     * @return  array<string, array{0: string, 1: string, 2: string}>
     *
     * @since   1.0.1
     */
    public static function unusableReplies(): array
    {
        return [
            'distillation refused'  => [
                'parseDistillation',
                '{"stop_reason": "refusal", "content": []}',
                'refused the distillation request',
            ],
            'distillation cut off'  => [
                'parseDistillation',
                '{"stop_reason": "max_tokens", "content": [{"type": "text", "text": "{\"rules\": ["}]}',
                'distillation request is too large',
            ],
            'distillation is prose' => [
                'parseDistillation',
                '{"content": [{"type": "text", "text": "I found three rules."}]}',
                'unreadable distillation',
            ],
            'rules are missing'     => [
                'parseDistillation',
                '{"content": [{"type": "text", "text": "{\"candidates\": []}"}]}',
                'unreadable distillation',
            ],
            'normalisation cut off' => [
                'parseNormalisation',
                '{"stop_reason": "max_tokens", "content": []}',
                'normalisation request is too large',
            ],
            'words are missing'     => [
                'parseNormalisation',
                '{"content": [{"type": "text", "text": "{\"forms\": []}"}]}',
                'unreadable normalisation',
            ],
            'not even a reply'      => ['parseNormalisation', '<html>502 Bad Gateway</html>', 'unreadable normalisation'],
        ];
    }

    /**
     * An API reply carrying a JSON object.
     *
     * @param   array  $answer  The object the model answered with.
     *
     * @return  string  The response body.
     *
     * @since   1.0.1
     */
    private static function reply(array $answer): string
    {
        return json_encode(['content' => [['type' => 'text', 'text' => json_encode($answer)]]]);
    }

    /**
     * Call one of the plugin's own methods.
     *
     * Private for the same reason as the translation plugin's, and reached the same way: the
     * readers take a response body and return what they could make of it, without touching
     * the plugin's state.
     *
     * @param   string  $method     The method to call.
     * @param   array   $arguments  The arguments to call it with.
     *
     * @return  mixed  Whatever the method returns.
     *
     * @since   1.0.1
     */
    private static function call(string $method, array $arguments)
    {
        $plugin     = (new ReflectionClass(Claude::class))->newInstanceWithoutConstructor();
        $reflection = new ReflectionMethod(Claude::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($plugin, $arguments);
    }
}
