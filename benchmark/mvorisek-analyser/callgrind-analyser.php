<?php

require_once __DIR__ . '/callgrind-parser.php';

abstract class CallgridAnalyserNode {}

abstract class CallgridAnalyserPositionInfo extends CallgridAnalyserNode {
    public string $binaryPath;
    public string $sourcePath;
}

class CallgridAnalyserPositionInfoFunction extends CallgridAnalyserPositionInfo {
    public string $functionName;
}

class CallgridAnalyserPositionInfoInlined extends CallgridAnalyserPositionInfo {}

class CallgridAnalyserPositionInfoInlinedExit extends CallgridAnalyserNode {}

class CallgridAnalyserPositionInfoCall extends CallgridAnalyserPositionInfo {
    public string $functionName;
}

class CallgridAnalyserPositionInfoJump extends CallgridAnalyserPositionInfo {}

class CallgridAnalyserFunction extends CallgridAnalyserNode {
    public CallgridAnalyserPositionInfoFunction $positionInfo;
    /** @var non-empty-list<CallgridParserCosts|CallgridAnalyserPositionInfoInlined|CallgridAnalyserPositionInfoInlinedExit|CallgridAnalyserPositionInfoCall|CallgridAnalyserPositionInfoJump|CallgridParserComment> */
    public array $nodes;
}

class CallgridAnalyserHeader extends CallgridAnalyserNode {
    public CallgridParserNode $node;
}

class CallgridAnalyser {
    /**
     * @param list<CallgridParserComment|CallgridParserHeader|CallgridParserHeaderEvents|CallgridParserCosts|CallgridParserPositionInfo> $parsed
     *
     * @return list<CallgridAnalyserFunction|CallgridAnalyserHeader|CallgridParserComment>
     */
    public function parse(array $parsed): array
    {
        $res = [];

        $lastFunction = null;
        $lastPositionInfo = null;
        $lastPositionInfoCall = null;
        $lastPositionInfoJump = null;

        foreach ($parsed as $parserNode) {
            if ($parserNode instanceof CallgridParserPositionInfo) {
                if ($parserNode->type === 'fi') {
                    assert($parserNode->value !== $lastFunction->positionInfo->sourcePath);

                    $node = new CallgridAnalyserPositionInfoInlined();
                    $node->binaryPath = $lastPositionInfo->binaryPath;
                    $node->sourcePath = $parserNode->value;

                    $lastFunction->nodes[] = $node;

                    $lastPositionInfo = $node;
                } elseif ($parserNode->type === 'fe') {
                    assert($parserNode->value === $lastFunction->positionInfo->sourcePath);

                    $lastFunction->nodes[] = new CallgridAnalyserPositionInfoInlinedExit();

                    $lastPositionInfo = $lastFunction->positionInfo;
                } else {
                    $typePrefix = substr($parserNode->type, 0, 1) !== 'f' && $parserNode->type !== 'ob'
                        ? substr($parserNode->type, 0, 1)
                        : '';

                    $positionInfoClass = [
                        '' => CallgridAnalyserPositionInfoFunction::class,
                        'c' => CallgridAnalyserPositionInfoCall::class,
                        'j' => CallgridAnalyserPositionInfoJump::class,
                    ][$typePrefix];

                    if ($typePrefix === 'c' && $lastPositionInfoCall !== null) {
                        $node = $lastPositionInfoCall;
                    } elseif ($typePrefix === 'j' && $lastPositionInfoJump !== null) {
                        $node = $lastPositionInfoJump;
                    } else {
                        $node = new $positionInfoClass();
                        if (isset($lastPositionInfo->binaryPath)) {
                            $node->binaryPath = $lastPositionInfo->binaryPath;
                        }
                        if (isset($lastPositionInfo->sourcePath)) {
                            $node->sourcePath = $lastPositionInfo->sourcePath;
                        }

                        if ($typePrefix === 'c') {
                            $lastPositionInfoCall = $node;
                        } elseif ($typePrefix === 'j') {
                            $lastPositionInfoJump = $node;
                        }
                    }

                    if ($parserNode->type === $typePrefix . 'ob') {
                        $node->binaryPath = $parserNode->value;
                    } elseif ($parserNode->type === ($typePrefix === '' ? 'fl' : $typePrefix . 'fi')) {
                        $node->sourcePath = $parserNode->value;
                    } elseif ($parserNode->type === $typePrefix . 'fn') {
                        assert($node instanceof CallgridAnalyserPositionInfoFunction || $node instanceof CallgridAnalyserPositionInfoCall);

                        $node->functionName = $parserNode->value;
                    } else {
                        throw new \Exception('Unexpected position info type: ' . $parserNode->type);
                    }

                    if ($typePrefix === '') {
                        $lastPositionInfo = $node;

                        assert($lastPositionInfoCall === null);
                        assert($lastPositionInfoJump === null);

                        if ($parserNode->type === 'fn') {
                            $lastFunction = new CallgridAnalyserFunction();
                            $lastFunction->positionInfo = $lastPositionInfo;

                            $res[] = $lastFunction;
                        }
                    }
                }
            } elseif ($parserNode instanceof CallgridParserCosts) {
                if ($parserNode->type !== null) {
                    if ($parserNode->type === 'calls') {
                        assert($lastPositionInfoCall !== null);

                        $lastFunction->nodes[] = $lastPositionInfoCall;
                        $lastPositionInfoCall = null;
                    } else {
                        assert($parserNode->type === 'jump' || $parserNode->type === 'jcnd');

                        if ($lastPositionInfoJump === null) {
                            $lastPositionInfoJump = new CallgridAnalyserPositionInfoJump();
                            $lastPositionInfoJump->binaryPath = $lastPositionInfo->binaryPath;
                            $lastPositionInfoJump->sourcePath = $lastPositionInfo->sourcePath;
                        }

                        $lastFunction->nodes[] = $lastPositionInfoJump;
                        $lastPositionInfoJump = null;
                    }
                }

                $lastFunction->nodes[] = $parserNode;
            } elseif ($parserNode instanceof CallgridParserComment) {
                if ($lastFunction !== null) {
                    $lastFunction->nodes[] = $parserNode;
                } else {
                    $res[] = $parserNode;
                }
            } else {
                assert($parserNode instanceof CallgridParserHeader || $parserNode instanceof CallgridParserHeaderEvents);

                $node = new CallgridAnalyserHeader();
                $node->node = $parserNode;

                $res[] = $node;
            }
        }

        assert($lastPositionInfoCall === null);
        assert($lastPositionInfoJump === null);

        // TODO
        // https://bugs.kde.org/show_bug.cgi?id=518565

        return $res;
    }
}

$fileData = file_get_contents(__DIR__ . '/callgrind.txt');
$fileData = file_get_contents(__DIR__ . '/sep-calls/callgrind.out.symfony-demo.2');

$callgridParser = new CallgridParser();
$parsed = $callgridParser->parse($fileData);

$callgridAnalyser = new CallgridAnalyser();
$parsed = $callgridAnalyser->parse($parsed);

usort($parsed, static function ($a, $b) {
    if (!$a instanceof CallgridAnalyserFunction || !$b instanceof CallgridAnalyserFunction) {
        return 0;
    }

    return strrev($a->positionInfo->functionName) <=> strrev($b->positionInfo->functionName);
});

$lastEvents = [];
foreach ($parsed as $node) {
    if ($node instanceof CallgridAnalyserHeader && $node->node instanceof CallgridParserHeaderEvents && $node->node->type === 'events') {
        $lastEvents = $node->node;
    }
}

ob_start();
foreach ($parsed as $function) {
    if (!$function instanceof CallgridAnalyserFunction) {
        continue;
    }

    $totals = [];
    $prevNode = null;
    foreach ($function->nodes as $node) {
        if ($node instanceof CallgridParserCosts && (!$prevNode instanceof CallgridParserCosts || $prevNode->type !== 'calls')) {
            foreach ($node->costs as $k => $v) {
                if (!isset($totals[$k])) {
                    $totals[$k] = 0;
                }

                $totals[$k] += $v;
            }
        }

        $prevNode = $node;
    }

    print_r([
        $function->positionInfo,
        array_combine($lastEvents->values, $totals)['Ir'],
    ]);
    echo "\n";
}
file_put_contents(__DIR__ . '/2.out', ob_get_clean());
