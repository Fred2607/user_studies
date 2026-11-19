<?php

namespace App\Services\Training;

use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Splits the generated Module 2 HTML (interactive condition) around its `<div data-role="decision-point">`, so the
 * platform can put the annotated email before it and show the decision point only after all clues are opened.
 *
 * The tree is cut at the decision point: `before` keeps what precedes it in document order, `after` what follows,
 * both as balanced HTML (the ancestors of the decision point appear in both parts; their id is dropped from
 * `after` to avoid duplicates). The radio buttons of the decision point are read here, so the server knows the
 * valid answers and their labels: the browser only sends the index of the chosen radio.
 *
 * Per-option feedback written by the model (e.g. `<div data-role="feedback" data-feedback-for="click" hidden>`) is
 * taken out of the parts and returned in `feedback` (option index => visible HTML, without scripts and without the
 * attributes a generated script could use to find it): the platform shows the feedback of the saved option itself,
 * because the generated scripts that toggle it cannot rely on their position once the section is split. An element is
 * the feedback of an option when one of FEEDBACK_ATTRIBUTES, or its id (feedback-<value>, <value>-feedback), equals
 * the option value; options with a repeated value are never mapped. Unmatched elements stay where they are.
 */
final class ScenarioSection
{
    public const MAX_LABEL = 200;

    /** Attributes that link an element to the value of a radio of the decision point. */
    public const FEEDBACK_ATTRIBUTES = ['data-feedback-for', 'data-feedback', 'data-for', 'data-option', 'data-choice', 'data-answer', 'data-value'];

    /** Elements that are part of the question itself or not content, never taken as feedback. */
    private const NOT_FEEDBACK = ['input', 'label', 'select', 'option', 'button', 'form', 'script', 'style', 'template', 'textarea'];

    /**
     * @return array{before: string, decision: ?string, after: string, options: list<array{value: string, label: string}>, feedback: array<int, string>}
     */
    public static function split(string $html): array
    {
        [$doc, $body] = HtmlFragment::load($html);
        $target = (new DOMXPath($doc))->query('//*[@data-role="decision-point"]')->item(0);
        if (! $target instanceof DOMElement) {
            return ['before' => HtmlFragment::innerHtml($body), 'decision' => null, 'after' => '', 'options' => [], 'feedback' => []];
        }

        $options = self::options($target);
        $feedback = self::extractFeedback($body, $options);  // before the path: it removes the feedback elements
        $path = self::path($body, $target);
        $decision = HtmlFragment::outerHtml($target);

        $before = $body->cloneNode(true);
        self::cut($before, $path, false);
        $after = $body->cloneNode(true);
        self::cut($after, $path, true);

        return [
            'before' => HtmlFragment::innerHtml($before),
            'decision' => $decision,
            'after' => HtmlFragment::innerHtml($after),
            'options' => $options,
            'feedback' => $feedback,
        ];
    }

    /**
     * Removes from the tree the feedback elements of the options and returns their visible HTML by option index.
     *
     * @param  list<array{value: string, label: string}>  $options
     * @return array<int, string>
     */
    private static function extractFeedback(DOMElement $body, array $options): array
    {
        $counts = array_count_values(array_column($options, 'value'));
        $xpath = new DOMXPath($body->ownerDocument);
        $found = [];  // list of [option index, element], in document order
        foreach ($xpath->query('.//*', $body) as $element) {
            if (! $element instanceof DOMElement || in_array(strtolower($element->nodeName), self::NOT_FEEDBACK, true)) {
                continue;
            }
            foreach ($found as [, $taken]) {
                if (self::contains($taken, $element)) {
                    continue 2;  // inside an element already taken
                }
            }
            if ($xpath->query('.//input | .//select | .//textarea', $element)->length > 0) {
                continue;  // holds the question (the decision point or one of its ancestors)
            }
            foreach (self::feedbackKeys($element) as $key) {
                if (($counts[$key] ?? 0) === 1) {
                    $found[] = [array_search($key, array_column($options, 'value'), true), $element];
                    break;
                }
            }
        }

        $feedback = [];
        foreach ($found as [$index, $element]) {
            $feedback[$index] = ($feedback[$index] ?? '') . self::visibleHtml($element);
            $element->parentNode->removeChild($element);
        }
        ksort($feedback);

        return $feedback;
    }

    /** @return list<string> option values an element may refer to (feedback attributes, then id patterns) */
    private static function feedbackKeys(DOMElement $element): array
    {
        $keys = [];
        foreach (self::FEEDBACK_ATTRIBUTES as $attribute) {
            if ($element->hasAttribute($attribute)) {
                $keys[] = trim($element->getAttribute($attribute));
            }
        }
        $id = trim($element->getAttribute('id'));
        if (preg_match('/^(?:feedback|fb)[-_](.+)$/i', $id, $m) || preg_match('/^(.+)[-_](?:feedback|fb)$/i', $id, $m)) {
            $keys[] = $m[1];
        }

        return $keys;
    }

    private static function contains(DOMNode $ancestor, DOMNode $node): bool
    {
        for ($node = $node->parentNode; $node !== null; $node = $node->parentNode) {
            if ($node === $ancestor) {
                return true;
            }
        }

        return false;
    }

    /**
     * HTML of a feedback element made visible and inert: no scripts (it is inserted by a Livewire update, which would
     * eval them), no hidden state, no attribute a generated script could use to find it and hide it again.
     */
    private static function visibleHtml(DOMElement $element): string
    {
        $copy = $element->cloneNode(true);
        foreach (iterator_to_array($copy->getElementsByTagName('script')) as $script) {
            $script->parentNode->removeChild($script);
        }
        foreach ([...self::FEEDBACK_ATTRIBUTES, 'id', 'data-role', 'hidden', 'aria-hidden'] as $attribute) {
            $copy->removeAttribute($attribute);
        }
        $style = array_filter(array_map('trim', explode(';', $copy->getAttribute('style'))),
            fn ($rule) => $rule !== '' && ! preg_match('/^(display\s*:\s*none|visibility\s*:\s*hidden)\b/i', $rule));
        $style === [] ? $copy->removeAttribute('style') : $copy->setAttribute('style', implode('; ', $style));
        $class = array_filter(preg_split('/\s+/', trim($copy->getAttribute('class'))),
            fn ($c) => $c !== '' && ! in_array(strtolower($c), ['hidden', 'd-none', 'invisible'], true));
        $class === [] ? $copy->removeAttribute('class') : $copy->setAttribute('class', implode(' ', $class));

        return HtmlFragment::outerHtml($copy);
    }

    /** @return list<int> child indexes from the root to the node */
    private static function path(DOMNode $root, DOMNode $node): array
    {
        $path = [];
        while ($node !== $root) {
            $index = 0;
            for ($sibling = $node->previousSibling; $sibling !== null; $sibling = $sibling->previousSibling) {
                $index++;
            }
            array_unshift($path, $index);
            $node = $node->parentNode;
        }

        return $path;
    }

    /**
     * Removes the node at $path and everything after it ($keepAfter false) or before it ($keepAfter true),
     * at every level of the tree.
     */
    private static function cut(DOMNode $root, array $path, bool $keepAfter): void
    {
        $node = $root;
        foreach ($path as $depth => $index) {
            $child = $node->childNodes->item($index);
            $isTarget = $depth === count($path) - 1;
            if ($keepAfter) {
                while ($child->previousSibling !== null) {
                    $node->removeChild($child->previousSibling);
                }
                if ($child instanceof DOMElement && ! $isTarget) {
                    $child->removeAttribute('id');  // ancestor already printed in the "before" part
                }
            } else {
                while ($child->nextSibling !== null) {
                    $node->removeChild($child->nextSibling);
                }
            }
            if ($isTarget) {
                $node->removeChild($child);
            }
            $node = $child;
        }
    }

    /** @return list<array{value: string, label: string}> radios of the decision point, in document order */
    private static function options(DOMElement $decision): array
    {
        $xpath = new DOMXPath($decision->ownerDocument);
        $options = [];
        foreach ($xpath->query('.//input', $decision) as $input) {
            if (strtolower(trim($input->getAttribute('type'))) !== 'radio') {
                continue;
            }
            $value = $input->hasAttribute('value') ? $input->getAttribute('value') : 'on';
            $options[] = ['value' => mb_substr($value, 0, 100), 'label' => mb_substr(self::label($input, $xpath), 0, self::MAX_LABEL)];
        }

        return $options;
    }

    /** Label of a radio: enclosing <label>, <label for=id>, or the text that follows it up to the next control. */
    private static function label(DOMElement $input, DOMXPath $xpath): string
    {
        for ($node = $input->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            if (strtolower($node->nodeName) === 'label') {
                return HtmlFragment::text($node);
            }
        }
        $id = $input->getAttribute('id');
        if ($id !== '') {
            foreach ($xpath->query('//label') as $label) {
                if ($label->getAttribute('for') === $id) {
                    return HtmlFragment::text($label);
                }
            }
        }
        $text = '';
        for ($node = $input->nextSibling; $node !== null; $node = $node->nextSibling) {
            if ($node instanceof DOMElement && in_array(strtolower($node->nodeName), ['input', 'br', 'button', 'p', 'div'], true)) {
                break;
            }
            $text .= ' ' . $node->textContent;
        }

        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}
