<?php

declare(strict_types=1);

/*
 * This file is part of PHP CS Fixer.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *     Dariusz Rumiński <dariusz.ruminski@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace PhpCsFixer\Fixer\FunctionNotation;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\FCT;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\Tokenizer\TokensAnalyzer;

/**
 * @author Gregor Harlan
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise.
 */
final class UseArrowFunctionsFixer extends AbstractFixer
{
    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Anonymous functions with return as the only statement must use arrow functions.',
            [
                new CodeSample(
                    <<<'SAMPLE'
                        <?php
                        foo(function ($a) use ($b) {
                            return $a + $b;
                        });

                        SAMPLE,
                ),
            ],
            null,
            'Risky when using `isset()` on outside variables that are not imported with `use ()`.',
        );
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isAllTokenKindsFound([\T_FUNCTION, \T_RETURN]);
    }

    public function isRisky(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     *
     * Must run before FunctionDeclarationFixer.
     */
    public function getPriority(): int
    {
        return 32;
    }

    protected function applyFix(\SplFileInfo $file, Tokens $tokens): void
    {
        $analyzer = new TokensAnalyzer($tokens);
        $constantExpressionClosures = $this->findConstantExpressionClosures($tokens);

        for ($index = $tokens->count() - 1; $index > 0; --$index) {
            if (!$tokens[$index]->isGivenKind(\T_FUNCTION) || !$analyzer->isLambda($index)) {
                continue;
            }

            // Closures are allowed in constant expressions as of PHP 8.5, but arrow
            // functions are not, as they capture the enclosing scope implicitly.
            if (isset($constantExpressionClosures[$index])) {
                continue;
            }

            // Find parameters

            $parametersStart = $tokens->getNextMeaningfulToken($index);

            if ($tokens[$parametersStart]->isGivenKind(CT::T_RETURN_REF)) {
                $parametersStart = $tokens->getNextMeaningfulToken($parametersStart);
            }

            $parametersEnd = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS, $parametersStart);

            // Find `use ()` start and end
            // Abort if it contains reference variables

            $next = $tokens->getNextMeaningfulToken($parametersEnd);

            $useStart = null;
            $useEnd = null;

            if ($tokens[$next]->isGivenKind(CT::T_USE_LAMBDA)) {
                $useStart = $next;

                if ($tokens[$useStart - 1]->isGivenKind(\T_WHITESPACE)) {
                    --$useStart;
                }

                $next = $tokens->getNextMeaningfulToken($next);

                while (!$tokens[$next]->equals(')')) {
                    if ($tokens[$next]->equals('&')) {
                        // variables used by reference are not supported by arrow functions
                        continue 2;
                    }

                    $next = $tokens->getNextMeaningfulToken($next);
                }

                $useEnd = $next;
                $next = $tokens->getNextMeaningfulToken($next);
            }

            // Find opening brace and following `return`
            // Abort if there is more than whitespace between them (like comments)

            $braceOpen = $tokens[$next]->equals('{') ? $next : $tokens->getNextTokenOfKind($next, ['{']);
            $return = $braceOpen + 1;

            if ($tokens[$return]->isGivenKind(\T_WHITESPACE)) {
                ++$return;
            }

            if (!$tokens[$return]->isGivenKind(\T_RETURN)) {
                continue;
            }

            // Find semicolon of `return` statement

            $semicolon = $tokens->getNextTokenOfKind($return, ['{', ';']);

            if (!$tokens[$semicolon]->equals(';')) {
                continue;
            }

            // Find closing brace
            // Abort if there is more than whitespace between semicolon and closing brace

            $braceClose = $semicolon + 1;

            if ($tokens[$braceClose]->isGivenKind(\T_WHITESPACE)) {
                ++$braceClose;
            }

            if (!$tokens[$braceClose]->equals('}')) {
                continue;
            }

            // Abort if closure has `use()` clause and return statement includes external files.
            // Converting such closures to arrow functions changes behaviour as the used variables
            // are no longer exposed to the included file.
            if (null !== $useStart && $this->containsIncludeOrRequire($tokens, $return, $semicolon)) {
                continue;
            }

            // Transform the function to an arrow function
            $this->transform($tokens, $index, $useStart, $useEnd, $braceOpen, $return, $semicolon, $braceClose);
        }
    }

    /**
     * Get the indices of the closures that are part of a constant expression.
     *
     * Such closures are found in attribute arguments, constant declarations, enum cases,
     * property defaults and parameter defaults. Bodies of closures, methods and property
     * hooks hold runtime code, so closures there are not part of the constant expression.
     *
     * @return array<int, true>
     */
    private function findConstantExpressionClosures(Tokens $tokens): array
    {
        $closures = [];

        foreach ($tokens as $index => $token) {
            if ($token->isGivenKind(FCT::T_ATTRIBUTE)) {
                $start = $index;
                $end = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_ATTRIBUTE, $start);
            } elseif ($token->isGivenKind(\T_CONST)) {
                $start = $index;
                $end = $this->findConstantDeclarationEnd($tokens, $start);
            } elseif ($token->isGivenKind([\T_FUNCTION, \T_FN])) {
                $start = $this->findParameterListStart($tokens, $index);

                if (null === $start) {
                    continue;
                }

                $end = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS, $start);
            } elseif ($token->isClassy()) {
                $start = $this->findClassyBodyStart($tokens, $index);

                if (null === $start) {
                    continue;
                }

                $end = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_BRACE, $start);
            } else {
                continue;
            }

            $closures += $this->findFunctionsOutsideOfBraces($tokens, $start, $end);
        }

        return $closures;
    }

    /**
     * Get the functions found between the given indices, skipping over any braces.
     *
     * Braces inside a constant expression can only open the body of a closure or of a
     * property hook, both of which hold runtime code.
     *
     * @return array<int, true>
     */
    private function findFunctionsOutsideOfBraces(Tokens $tokens, int $start, int $end): array
    {
        $functions = [];

        for ($index = $start + 1; $index < $end; ++$index) {
            if ($tokens[$index]->equals('{')) {
                $index = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_BRACE, $index);
            } elseif ($tokens[$index]->isGivenKind(CT::T_PROPERTY_HOOK_BRACE_OPEN)) {
                $index = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PROPERTY_HOOK, $index);
            } elseif ($tokens[$index]->isGivenKind(\T_FUNCTION)) {
                $functions[$index] = true;
            }
        }

        return $functions;
    }

    /**
     * Get the index of the token that ends the constant declaration starting at the given index.
     */
    private function findConstantDeclarationEnd(Tokens $tokens, int $index): int
    {
        for ($count = $tokens->count(), ++$index; $index < $count; ++$index) {
            $blockType = Tokens::detectBlockType($tokens[$index]);

            if (null !== $blockType && $blockType['isStart']) {
                $index = $tokens->findBlockEnd($blockType['type'], $index);

                continue;
            }

            // A close tag ends the statement like a semicolon does, while a block
            // end means the declaration is malformed and must not be followed further.
            if (null !== $blockType || $tokens[$index]->equals(';') || $tokens[$index]->isGivenKind(\T_CLOSE_TAG)) {
                break;
            }
        }

        return $index;
    }

    /**
     * Get the index of the parenthesis that opens the parameter list of a declaration.
     */
    private function findParameterListStart(Tokens $tokens, int $index): ?int
    {
        $position = $tokens->getNextMeaningfulToken($index);

        if (null !== $position && $tokens[$position]->isGivenKind(CT::T_RETURN_REF)) {
            $position = $tokens->getNextMeaningfulToken($position);
        }

        // Skip the name of a function declaration, as lambdas have none.
        if (null !== $position && $tokens[$position]->isGivenKind(\T_STRING)) {
            $position = $tokens->getNextMeaningfulToken($position);
        }

        return null !== $position && $tokens[$position]->equals('(') ? $position : null;
    }

    /**
     * Get the index of the brace that opens the body of a class, interface, trait or enum.
     */
    private function findClassyBodyStart(Tokens $tokens, int $index): ?int
    {
        $position = $tokens->getNextTokenOfKind($index, ['(', '{']);

        if (null === $position) {
            return null;
        }

        // Skip the argument list of an anonymous class, as it may itself
        // contain braces, for instance those of a closure passed to it.
        if ($tokens[$position]->equals('(')) {
            $position = $tokens->getNextTokenOfKind($tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS, $position), ['{']);
        }

        return $position;
    }

    private function transform(Tokens $tokens, int $index, ?int $useStart, ?int $useEnd, int $braceOpen, int $return, int $semicolon, int $braceClose): void
    {
        $tokensToInsert = [new Token([\T_DOUBLE_ARROW, '=>'])];

        if ($tokens->getNextMeaningfulToken($return) === $semicolon) {
            $tokensToInsert[] = new Token([\T_WHITESPACE, ' ']);
            $tokensToInsert[] = new Token([\T_STRING, 'null']);
        }

        $tokens->clearRange($semicolon, $braceClose - 1);
        $tokens->clearTokenAndMergeSurroundingWhitespace($braceClose);

        $tokens->clearRange($braceOpen + 1, $return);
        $tokens->overrideRange($braceOpen, $braceOpen, $tokensToInsert);

        if (null !== $useStart) {
            $tokens->clearRange($useStart, $useEnd);
        }

        $tokens[$index] = new Token([\T_FN, 'fn']);
    }

    /**
     * Check if the return statement contains include/include_once/require/require_once.
     */
    private function containsIncludeOrRequire(Tokens $tokens, int $start, int $end): bool
    {
        for ($i = $start; $i < $end; ++$i) {
            if ($tokens[$i]->isGivenKind([\T_INCLUDE, \T_INCLUDE_ONCE, \T_REQUIRE, \T_REQUIRE_ONCE])) {
                return true;
            }
        }

        return false;
    }
}
