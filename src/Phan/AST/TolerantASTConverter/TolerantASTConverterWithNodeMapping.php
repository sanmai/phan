<?php declare(strict_types=1);

namespace Phan\AST\TolerantASTConverter;

use ast;
use Microsoft\PhpParser;
use Microsoft\PhpParser\Diagnostic;
use Microsoft\PhpParser\Token;
use Microsoft\PhpParser\TokenKind;

/**
 * This is a subclass of TolerantASTConverter
 * that maps the original AST to the corresponding generated ast\Node.
 *
 * This is planned for use with "Go to definition" requests, completion requests, etc.
 *
 * (This lets you know the byte offset of a given node and how long that node is)
 *
 * Workflow:
 *
 * 1. A request is received for finding the type definition of the ast\Node at byte offset 100 in a given file
 * 2. Phan will figure out which PhpParser\Token that is referring to.
 *
 *    If this is a property, method invocation, constant, etc.,
 *    this will refer to the property access (Not the name), constant access, etc.
 *
 *    This is done via iterating over the tokens, finding the token that contains the offset,
 *    then walking back up to the parent
 * 3. Then, Phan will use $node_mapping to update the corresponding AST node
 *
 * @phan-file-suppress PhanPartialTypeMismatchArgument
 * @phan-file-suppress PhanUndeclaredProperty deliberately adding dynamic property
 */
class TolerantASTConverterWithNodeMapping extends TolerantASTConverter
{
    /**
     * @var PhpParser\Node|PhpParser\Token|null
     * TODO: If this is null, then just create a parent instance and call that.
     */
    private static $closest_node_or_token;

    /** @var int */
    private $expected_byte_offset;

    public function __construct(int $expected_byte_offset)
    {
        fwrite(STDERR, "Searching for byte offset $expected_byte_offset\n");
        $this->expected_byte_offset = $expected_byte_offset;
    }

    /**
     * @param Diagnostic[] &$errors @phan-output-reference
     *
     * @return \ast\Node
     */
    public function parseCodeAsPHPAST(string $file_contents, int $version, array &$errors = [])
    {
        $found = false;
        // Force the byte offset to be within the
        $byte_offset = \max(0, \min(\strlen($file_contents), $this->expected_byte_offset));

        if (!\in_array($version, self::SUPPORTED_AST_VERSIONS)) {
            throw new \InvalidArgumentException(sprintf("Unexpected version: want %s, got %d", \implode(', ', self::SUPPORTED_AST_VERSIONS), $version));
        }

        // Aside: this can be implemented as a stub.
        try {
            $parser_node = static::phpParserParse($file_contents, $errors);
            self::findNodeAtOffset($parser_node, $byte_offset);
            $result = $this->phpParserToPhpast($parser_node, $version, $file_contents);
            $original_node = $parser_node;
            return $result;
        } finally {
            self::$closest_node_or_token = null;
        }
    }

    /**
     * Records the closest node or token to the given offset.
     * Heuristics are used to ensure that this can map to an ast\Node.
     * TODO: Finish implementing
     *
     * @return void
     */
    private static function findNodeAtOffset(PhpParser\Node $parser_node, int $offset)
    {
        fwrite(STDERR, "Looking for offset $offset\n");
        self::$closest_node_or_token = null;
        self::findNodeAtOffsetRecursive($parser_node, $offset);
    }

    private static function findNodeAtOffsetRecursive($parser_node, int $offset) : bool
    {
        foreach ($parser_node->getChildNodesAndTokens() as $node_or_token) {
            if ($node_or_token instanceof Token) {
                if ($node_or_token->getEndPosition() > $offset) {
                    if ($node_or_token->kind === TokenKind::Name) {
                        // We want the parent of a Name, e.g. a class
                        self::$closest_node_or_token = $parser_node;
                        fwrite(STDERR, "Found node: " . json_encode($parser_node) . "\n");
                        return true;
                    }
                    fwrite(STDERR, "Found token: " . json_encode($node_or_token));
                    self::$closest_node_or_token = $node_or_token;
                    // TODO: Handle other cases
                    return true;
                }
            }
            if ($node_or_token instanceof PhpParser\Node) {
                if (self::findNodeAtOffsetRecursive($node_or_token, $offset)) {
                    // fwrite(STDERR, "Found parent node: " . json_encode($parser_node) . "\n");
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param PhpParser\Node|Token $n - The node from PHP-Parser
     * @return ast\Node|ast\Node[]|string|int|float|bool - whatever ast\parse_code would return as the equivalent.
     * @throws InvalidNodeException when self::$should_add_placeholders is false, like many of these methods.
     */
    protected static function phpParserNodeToAstNodeOrPlaceholderExpr($n)
    {
        // fprintf(STDERR, "Comparing %s to %s\n", get_class($n), get_class(self::$closest_node_or_token));
        $ast_node = parent::phpParserNodeToAstNodeOrPlaceholderExpr($n);
        if ($n === self::$closest_node_or_token) {
            fwrite(STDERR, "Marking corresponding node as flagged: " . \Phan\Debug::nodeToString($ast_node) . "\n");
            // fflush(STDERR);
            if ($ast_node instanceof ast\Node) {
                $ast_node->isSelected = true;
            }
        }
        return $ast_node;
    }

    /**
     * @param PhpParser\Node|Token $n - The node from PHP-Parser
     * @return ast\Node|ast\Node[]|string|int|float|bool|null - whatever ast\parse_code would return as the equivalent.
     */
    protected static function phpParserNodeToAstNode($n)
    {
        $ast_node = parent::phpParserNodeToAstNode($n);
        if ($n === self::$closest_node_or_token) {
            fwrite(STDERR, "Marking corresponding node as flagged: " . \Phan\Debug::nodeToString($ast_node) . "\n");
            if ($ast_node instanceof ast\Node) {
                $ast_node->isSelected = true;
            }
        }
        return $ast_node;
    }

    /**
     * @param PhpParser\Node|Token $n - The node from PHP-Parser
     * @return ast\Node|ast\Node[]|string|int|float|bool|null - whatever ast\parse_code would return as the equivalent.
     * @override
     */
    protected static function phpParserNonValueNodeToAstNode($n)
    {
        // fprintf(STDERR, "Comparing %s to %s\n", get_class($n), get_class(self::$closest_node_or_token));
        $ast_node = parent::phpParserNonValueNodeToAstNode($n);
        if ($n === self::$closest_node_or_token) {
            fwrite(STDERR, "Marking corresponding node as flagged: " . \Phan\Debug::nodeToString($ast_node) . "\n");
            if ($ast_node instanceof ast\Node) {
                // Create a dynamic property
                $ast_node->isSelected = true;
            }
        }
        return $ast_node;
    }

    /**
     * TODO: Call this for property name accesses, etc.
     *
     * Use in the base class if a node is created without calling phpParserNodeToAstNode
     *
     * @param PhpParser\Node|PhpParser\Token $n
     * @param ast\Node $ast_node
     * @return void
     */
    protected static function linkNode($n, $ast_node)
    {
        if ($n === self::$closest_node_or_token && $ast_node instanceof ast\Node) {
            $ast_node->isSelected = true;
        }
    }
}
