<?php
namespace Gajus\Dindent;

/**
 * @link https://github.com/gajus/dindent for the canonical source repository
 * @license https://github.com/gajus/dindent/blob/master/LICENSE BSD 3-Clause
 * @phpstan-type LogEntry array{rule: string, pattern: string, subject: string, match: string}
 * @phpstan-type Options array{indentation_character: string, logging: boolean}
 */
class Indenter {
    /**
     * @var LogEntry[]
     */
    private array $log = [];

    /**
     * @var Options
     */
    private array $options = [
        'indentation_character' => '    ',
        'logging' => false
    ];

    /**
     * @var string[]
     */
    private array $inline_elements =  ['b', 'big', 'i', 's', 'small', 'tt', 'q', 'u', 'abbr', 'acronym', 'cite', 'code', 'data', 'dfn', 'em', 'kbd', 'mark', 'strong', 'samp', 'time', 'var', 'a', 'bdi', 'bdo', 'br', 'img', 'span', 'sub', 'sup', 'wbr'];

    /**
     * @var list<string|null>
     */
    private array $temporary_replacements_script = [];

    /**
     * @var list<string|null>
     */
    private array $temporary_replacements_inline = [];

    /**
     * @param Options $options
     */
    public function __construct (array $options = []) {
        foreach ($options as $name => $value) {
            if (!array_key_exists($name, $this->options)) {
                throw new Exception\InvalidArgumentException('Unrecognized option.');
            }

            $this->options[$name] = $value;
        }
    }

    /**
     * @param string $element_name Element name, e.g. "b".
     * @param ElementType $type
     */
    public function setElementType (string $element_name, ElementType $type): void {
        if ($type === ElementType::Block) {
            $this->inline_elements = array_diff($this->inline_elements, array($element_name));
        } else if ($type === ElementType::Inline) {
            $this->inline_elements[] = $element_name;
        } else {
            throw new Exception\InvalidArgumentException('Unrecognized element type.');
        }
        $this->inline_elements = array_unique($this->inline_elements);
    }

    /**
     * @param string $input HTML input.
     * @return string Indented HTML.
     */
    public function indent(string $input): string {
        $this->log = [];

        // Dindent does not indent <script> body. Instead, it temporary removes it from the code, indents the input, and restores the script body.
        if (preg_match_all('/<script\b[^>]*>([\s\S]*?)<\/script>/mi', $input, $matches)) {
            $this->temporary_replacements_script = $matches[0] ?? null;
            foreach ($matches[0] as $i => $match) {
                $input = str_replace($match, '<script>' . ($i + 1) . '</script>', $input);
            }
        }

        // Removing double whitespaces to make the source code easier to read.
        // With exception of <pre>/ CSS white-space changing the default behaviour, double whitespace is meaningless in HTML output.
        // This reason alone is sufficient not to use Dindent in production.
        $input = str_replace("\t", '', $input);
        $input = preg_replace('/\s{2,}/u', ' ', $input);

        // Remove inline elements and replace them with text entities.
        if (preg_match_all('/<(' . implode('|', $this->inline_elements) . ')[^>]*>(?:[^<]*)<\/\1>/', $input, $matches)) {
            $this->temporary_replacements_inline = $matches[0] ?? null;
            foreach ($matches[0] as $i => $match) {
                $input = str_replace($match, 'ᐃ' . ($i + 1) . 'ᐃ', $input);
            }
        }

        $subject = $input;

        $output = '';

        $next_line_indentation_level = 0;

        do {
            $indentation_level = $next_line_indentation_level;

            $patterns = [
                // block tag
                '/^(<([a-z]+)(?:[^>]*)>(?:[^<]*)<\/(?:\2)>)/' => MatchType::NoIndent,
                // DOCTYPE
                '/^<!([^>]*)>/' => MatchType::NoIndent,
                // tag with implied closing
                '/^<(input|link|meta|base|br|img|source|hr)([^>]*)>/' => MatchType::NoIndent,
                // self closing SVG tags
                '/^<(animate|stop|path|circle|line|polyline|rect|use)([^>]*)\/>/' => MatchType::NoIndent,
                // opening tag
                '/^<[^\/]([^>]*)>/' => MatchType::IndentIncrease,
                // closing tag
                '/^<\/([^>]*)>/' => MatchType::IndentDecrease,
                // self-closing tag
                '/^<(.+)\/>/' => MatchType::IndentDecrease,
                // whitespace
                '/^(\s+)/' => MatchType::Discard,
                // text node
                '/([^<]+)/' => MatchType::NoIndent
            ];

            foreach ($patterns as $pattern => $rule) {
                if ($match = preg_match($pattern, $subject, $matches)) {
                    if ($this->options['logging']) {
                        $this->log[] = [
                            'rule' => $rule->asString(),
                            'pattern' => $pattern,
                            'subject' => $subject,
                            'match' => $matches[0]
                        ];
                    }

                    $subject = mb_substr($subject, mb_strlen($matches[0]));

                    if ($rule === MatchType::Discard) {
                        break;
                    }

                    if ($rule === MatchType::NoIndent) {

                    } else if ($rule === MatchType::IndentDecrease) {
                        $next_line_indentation_level--;
                        $indentation_level--;
                    } else {
                        $next_line_indentation_level++;
                    }

                    if ($indentation_level < 0) {
                        $indentation_level = 0;
                    }

                    $output .= str_repeat($this->options['indentation_character'], $indentation_level) . $matches[0] . "\n";

                    break;
                }
            }
        } while ($match);

        if ($this->options['logging']) {
            $interpreted_input = '';
            foreach ($this->log as $e) {
                $interpreted_input .= $e['match'];
            }

            if ($interpreted_input !== $input) {
                throw new Exception\RuntimeException('Did not reproduce the exact input.');
            }
        }

        $output = preg_replace('/(<(\w+)[^>]*>)\s*(<\/\2>)/u', '\\1\\3', $output);

        foreach ($this->temporary_replacements_script as $i => $original) {
            $output = str_replace('<script>' . ($i + 1) . '</script>', $original, $output);
        }

        foreach ($this->temporary_replacements_inline as $i => $original) {
            $output = str_replace('ᐃ' . ($i + 1) . 'ᐃ', $original, $output);
        }

        $this->temporary_replacements_script = [];
        $this->temporary_replacements_inline = [];

        return trim($output);
    }

    /**
     * Debugging utility. Get log for the last indent operation.
     *
     * @return LogEntry[]
     */
    public function getLog(): array {
        return $this->log;
    }
}
