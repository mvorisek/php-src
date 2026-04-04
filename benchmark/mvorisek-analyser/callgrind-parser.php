<?php

abstract class CallgridParserNode {}

class CallgridParserComment extends CallgridParserNode {
    public string $value;
}

class CallgridParserHeader extends CallgridParserNode {
    public string $type;
    public string $value;
}

class CallgridParserHeaderEvents extends CallgridParserNode {
    /** @var 'positions'|'events' */
    public string $type;
    /** @var list<non-empty-string> */
    public array $values;
}

class CallgridParserCostsCompressed extends CallgridParserNode {
    /** @var 'calls'|'jump'|'jcnd' */
    public ?string $type;
    public ?int $countA;
    public ?int $countB;
    /** @var list<int|non-empty-string> */
    public array $positions;
    /** @var list<int> */
    public array $costs;
}

class CallgridParserCosts extends CallgridParserNode {
    /** @var 'calls'|'jump'|'jcnd' */
    public ?string $type;
    public ?int $countA;
    public ?int $countB;
    /** @var list<int> */
    public array $positions;
    /** @var list<int> */
    public array $costs;
}

class CallgridParserPositionInfoCompressed extends CallgridParserNode {
    /** @var 'ob'|'fl'|'fi'|'fe'|'fn'|'cob'|'cfi'|'cfn'|'jfi'|'jfn' */
    public string $type;
    public ?int $number;
    /** @var non-empty-string */
    public ?string $value;
}

class CallgridParserPositionInfo extends CallgridParserNode {
    /** @var 'ob'|'fl'|'fi'|'fe'|'fn'|'cob'|'cfi'|'cfn'|'jfi'|'jfn' */
    public string $type;
    /** @var non-empty-string */
    public string $value;
}

class CallgridParser {
    private const NUMBER_REGEX = '(?:0x[0-9a-zA-F]++|[0-9]++)';

    /**
     * @return list<non-empty-string>
     */
    private function splitSpaceSeparatedList(string $line): array
    {
        return preg_split('~ +~', $line, -1, \PREG_SPLIT_NO_EMPTY);
    }

    private function parseNumber(string $value): int
    {
        assert(preg_match('~^' . self::NUMBER_REGEX . '$~', $value));

        return substr($value, 0, 2) === '0x'
            ? hexdec(substr($value, 2))
            : (int) $value;
    }

    /**
     * Based on https://valgrind.org/docs/manual/cl-format.html#cl-format.reference
     * and https://sourceware.org/git/?p=valgrind.git;a=blob;f=callgrind/callgrind_annotate.in;hb=refs/tags/VALGRIND_3_26_0#l541 .
     *
     * @return list<CallgridParserComment|CallgridParserHeader|CallgridParserCostsCompressed|CallgridParserPositionInfoCompressed>
     */
    public function parseRaw(string $data): array
    {
        $lines = explode("\n", $data);
        $lines = array_filter($lines, static fn($v) => $v !== '');

        assert($lines[0] === '# callgrind format');
        assert($lines[1] === 'version: 1');
        assert(preg_match('~^totals: ~', end($lines)) === 1);

        $lastPositionsCount = count(['line']);

        $res = [];
        foreach ($lines as $line) {
            if (substr($line, 0, 2) === '# ') {
                $node = new CallgridParserComment();
                $node->value = substr($line, 2);
            } elseif (preg_match('~^(version|creator|cmd|pid|thread|part|desc|event|positions|events|summary|totals): *(.*)$~', $line, $matches) === 1) {
                $node = new CallgridParserHeader();
                $node->type = $matches[1];
                $node->value = $matches[2];

                if ($node->type === 'positions') {
                    $lastPositionsCount = count($this->splitSpaceSeparatedList($node->value));
                }
            } elseif (preg_match('~^(?:(calls|jump|jcnd)= *)?((?:(?:[+\-]?' . self::NUMBER_REGEX . '|\*)(?: +|/|$))+)$~', $line, $matches) === 1) { // https://bugs.kde.org/show_bug.cgi?id=518563
                $node = new CallgridParserCostsCompressed();
                $node->type = $matches[1] === ''
                    ? null
                    : $matches[1];
                $values = $this->splitSpaceSeparatedList(str_replace('/', ' ', $matches[2]));
                $node->countA = $node->type === 'calls' || $node->type === 'jump' || $node->type === 'jcnd'
                    ? $this->parseNumber(array_shift($values))
                    : null;
                $node->countB = $node->type === 'jcnd'
                    ? $this->parseNumber(array_shift($values))
                    : null;
                $node->positions = array_slice($values, 0, $lastPositionsCount);
                $node->costs = array_map(fn ($v) => $this->parseNumber($v), array_slice($values, $lastPositionsCount));
            } elseif (preg_match('~^(ob|fl|fi|fe|fn|cob|cfi|cfn|jfi|jfn)= *(?:\((' . self::NUMBER_REGEX . ')\) *)?(.*)$~', $line, $matches) === 1) {
                $node = new CallgridParserPositionInfoCompressed();
                $node->type = $matches[1];
                $node->number = $matches[2] === ''
                    ? null
                    : $this->parseNumber($matches[2]);
                $node->value = $matches[3] === ''
                    ? null
                    : $matches[3];
            } else {
                throw new \Exception('Line does not match expected grammar: ' . $line);
            }

            $res[] = $node;
        }

        return $res;
    }

    /**
     * @return list<CallgridParserComment|CallgridParserHeader|CallgridParserHeaderEvents|CallgridParserCosts|CallgridParserPositionInfo>
     */
    public function parse(string $data): array
    {
        $rawNodes = $this->parseRaw($data);

        $lastPositionsCount = count(['line']);
        $lastCostsCount = false;

        $lastPositions = null;
        $decompressPositionsFx = function (array $positionsCompressed) use (&$lastPositions) {
            if ($lastPositions !== null) {
                assert(count($lastPositions) === count($positionsCompressed));
            }

            $res = [];
            foreach ($positionsCompressed as $k => $vCompressed) {
                if ($vCompressed === '*') {
                    $v = $lastPositions[$k];
                } elseif (substr($vCompressed, 0, 1) === '-' || substr($vCompressed, 0, 1) === '+') {
                    $v = $lastPositions[$k] + $this->parseNumber(substr($vCompressed, 1)) * (substr($vCompressed, 0, 1) === '-' ? -1 : 1);
                } else {
                    $v = $this->parseNumber($vCompressed);
                }

                $res[] = $v;
            }

            $lastPositions = $res;

            return $res;
        };

        $seenNames = [];
        $decompressNameFx = function (string $type, ?int $number, ?string $value) use (&$seenNames) {
            $context = [
                'ob' => 'ob',
                'fl' => 'fl',
                'fi' => 'fl',
                'fe' => 'fl',
                'fn' => 'fn',
                'cob' => 'ob',
                'cfi' => 'fl',
                'cfn' => 'fn',
                'jfi' => 'fl',
                'jfn' => 'fn',
            ][$type];

            assert($number !== null);

            if ($value === null) {
                return $seenNames[$context][$number];
            }

            if (isset($seenNames[$context][$number])) {
                assert($seenNames[$context][$number] === $value);
            } else {
                $seenNames[$context][$number] = $value;
            }

            return $value;
        };

        $res = [];
        foreach ($rawNodes as $rawNode) {
            if ($rawNode instanceof CallgridParserComment) {
                $node = $rawNode;
            } elseif ($rawNode instanceof CallgridParserHeader) {
                if ($rawNode->type === 'positions' || $rawNode->type === 'events') {
                    $node = new CallgridParserHeaderEvents();
                    $node->type = $rawNode->type;
                    $node->values = $this->splitSpaceSeparatedList($rawNode->value);

                    if ($rawNode->type === 'positions') {
                        $lastPositionsCount = count($node->values);
                    } else {
                        $lastCostsCount = count($node->values);
                    }
                } else {
                    $node = $rawNode;
                }
            } elseif ($rawNode instanceof CallgridParserCostsCompressed) {
                assert(count($rawNode->positions) === $lastPositionsCount);
                if ($rawNode->type === null) {
                    assert(count($rawNode->costs) <= $lastCostsCount);
                } else {
                    assert($rawNode->costs === []);
                }

                $node = new CallgridParserCosts();
                $node->type = $rawNode->type;
                $node->countA = $rawNode->countA;
                $node->countB = $rawNode->countB;
                $node->positions = $decompressPositionsFx($rawNode->positions);
                $node->costs = array_pad($rawNode->costs, $lastCostsCount, 0);
            } elseif ($rawNode instanceof CallgridParserPositionInfoCompressed) {
                $node = new CallgridParserPositionInfo();
                $node->type = $rawNode->type;
                $node->value = $decompressNameFx($rawNode->type, $rawNode->number, $rawNode->value);
            } else {
                throw new \Exception('Unexpected raw node: ' . get_class($rawNode));
            }

            $res[] = $node;
        }

        return $res;
    }
}

//$fileData = file_get_contents(__DIR__ . '/callgrind.txt');
//$fileData = file_get_contents(__DIR__ . '/sep-calls/callgrind.out.symfony-demo.2');
//
//$callgridParser = new CallgridParser();
//$parsed = $callgridParser->parse($fileData);
////print_r($parsed);
//
//$totals = [];
//$prevNode = null;
//$lastEvents = [];
//foreach ($parsed as $node) {
//    if ($node instanceof CallgridParserCosts && (!$prevNode instanceof CallgridParserCosts || $prevNode->type !== 'calls')) {
//        foreach ($node->costs as $k => $v) {
//            if (!isset($totals[$k])) {
//                $totals[$k] = 0;
//            }
//
//            $totals[$k] += $v;
//        }
//    } elseif ($node instanceof CallgridParserHeaderEvents && $node->type === 'events') {
//        $lastEvents = $node;
//    }
//
//    $prevNode = $node;
//}
//print_r(array_combine($lastEvents->values, $totals));
