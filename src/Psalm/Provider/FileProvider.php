<?php
namespace Psalm\Provider;

use PhpParser;
use Psalm\Checker\ProjectChecker;
use Psalm\LanguageServer\NodeVisitor\{ColumnCalculator, ReferencesAdder};

class FileProvider
{
    /**
     * @param  string  $file_path
     * @return boolean
     */
    public static function hasFileChanged($file_path)
    {
        return filemtime($file_path) > CacheProvider::getLastGoodRun();
    }

    /**
     * @param  ProjectChecker   $project_checker
     * @param  string           $file_path
     * @param  bool             $debug_output
     * @return array<int, \PhpParser\Node\Stmt>
     */
    public static function getStatementsForFile(ProjectChecker $project_checker, $file_path, $debug_output = false)
    {
        $stmts = [];

        $from_cache = false;

        $version = 'parsercache4.1' . ($project_checker->server_mode ? 'server' : '');

        $file_contents = $project_checker->getFileContents($file_path);
        $file_content_hash = md5($version . $file_contents);
        $file_cache_key = CacheProvider::getParserCacheKey($file_path);

        $stmts = CacheProvider::loadStatementsFromCache($file_path, $file_content_hash, $file_cache_key);

        if ($stmts === null) {
            if ($debug_output) {
                echo 'Parsing ' . $file_path . PHP_EOL;
            }

            $stmts = self::parseStatementsInFile($project_checker, $file_contents);
        } else {
            $from_cache = true;
        }

        CacheProvider::saveStatementsToCache($file_cache_key, $file_content_hash, $stmts, $from_cache);

        if (!$stmts) {
            return [];
        }

        return $stmts;
    }

    /**
     * @param  ProjectChecker   $project_checker
     * @param  string           $file_contents
     * @return array<int, \PhpParser\Node\Stmt>
     */
    private static function parseStatementsInFile(ProjectChecker $project_checker, $file_contents)
    {
        $attributes = ['comments', 'startLine', 'startFilePos', 'endFilePos'];

        if ($project_checker->server_mode) {
            $attributes[] = 'endLine';
        }

        $lexer = new PhpParser\Lexer(['usedAttributes' => $attributes]);

        $parser = (new PhpParser\ParserFactory())->create(PhpParser\ParserFactory::PREFER_PHP7, $lexer);

        $error_handler = new \PhpParser\ErrorHandler\Collecting();

        /** @var array<int, \PhpParser\Node\Stmt> */
        $stmts = $parser->parse($file_contents, $error_handler);

        if (!$stmts && $error_handler->hasErrors()) {
            foreach ($error_handler->getErrors() as $error) {
                throw $error;
            }
        }

        if ($project_checker->server_mode) {
            $traverser = new PhpParser\NodeTraverser;

            // Add parentNode, previousSibling, nextSibling attributes
            $traverser->addVisitor(new ReferencesAdder());

            // Add column attributes to nodes
            $traverser->addVisitor(new ColumnCalculator($file_contents));

            $traverser->traverse($stmts);
        }

        return $stmts;
    }

    /**
     * Returns the node at a specified position
     * @param array<PhpParser\Node> $stmts
     * @param \Psalm\LanguageServer\Protocol\Position $position
     * @return PhpParser\Node|null
     */
    public static function getNodeAtPosition(array $stmts, \Psalm\LanguageServer\Protocol\Position $position)
    {
        $traverser = new PhpParser\NodeTraverser;
        $finder = new \Psalm\LanguageServer\NodeVisitor\NodeAtPositionFinder($position);
        $traverser->addVisitor($finder);
        $traverser->traverse($stmts);
        return $finder->node;
    }
}