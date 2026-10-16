<?php
/**
 * Streams any XML file and converts each repeating record element it finds
 * into its own CSV file (one CSV per distinct record tag, since different
 * record types typically have different field sets).
 *
 * "Record elements" are auto-detected: a tag qualifies if some single parent
 * element contains two or more of it as direct children (e.g. <item> repeated
 * inside one <items>). When repeating tags are nested, only the innermost
 * level is used: a repeating container around other records is ignored.
 *
 * Usage:
 *   php xml_to_csv_conversion.php                   # convert every *.xml next to this script
 *   php xml_to_csv_conversion.php <input.xml>        # convert one file
 *   php xml_to_csv_conversion.php <input_dir>        # convert every *.xml in a directory
 *   php xml_to_csv_conversion.php <input> <out_dir>  # write CSVs into out_dir instead
 *
 * Output: one CSV per detected record tag, named <input>.<tag>.csv,
 * written next to each input file unless <out_dir> is given.
 */

$target = $argv[1] ?? __DIR__;
$outputDirArg = $argv[2] ?? null;

if (is_dir($target)) {
    $inputs = glob(rtrim($target, '/') . '/*.xml');
} elseif (is_file($target)) {
    $inputs = [$target];
} else {
    fwrite(STDERR, "Not a file or directory: $target\n");
    exit(1);
}

if (empty($inputs)) {
    fwrite(STDERR, "No XML files found in: $target\n");
    exit(1);
}

foreach ($inputs as $inputPath) {
    convertXmlToCsv($inputPath, $outputDirArg ?? dirname($inputPath));
}

function convertXmlToCsv(string $inputPath, string $outputDir): void
{
    echo "Converting $inputPath\n";

    $recordTags = detectRecordTags($inputPath);
    if (empty($recordTags)) {
        echo "  No repeating record elements found, skipping.\n";
        return;
    }

    $base = pathinfo($inputPath, PATHINFO_FILENAME);
    $outputs = [];
    foreach ($recordTags as $tag) {
        $outputs[$tag] = rtrim($outputDir, '/') . "/{$base}.{$tag}.csv";
    }

    // Pass 1: discover each record type's full set of field names, in
    // first-seen order, so the CSV header covers every column even when
    // individual records omit optional fields.
    $fields = array_fill_keys($recordTags, []);

    $reader = new XMLReader();
    $reader->open($inputPath);

    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || !isset($fields[$reader->name])) {
            continue;
        }
        $tag = $reader->name;
        $record = parseRecord($reader);
        foreach (array_keys($record) as $field) {
            $fields[$tag][$field] = true;
        }
    }
    $reader->close();

    foreach ($fields as $tag => $fieldSet) {
        $fields[$tag] = array_keys($fieldSet);
    }

    // Pass 2: stream again, writing each record as a CSV row now that the
    // headers are known.
    $handles = [];
    foreach ($outputs as $tag => $path) {
        $fh = fopen($path, 'w');
        if ($fh === false) {
            fwrite(STDERR, "  Could not open output file for writing: $path\n");
            exit(1);
        }
        fputcsv($fh, $fields[$tag], ',', '"', '\\');
        $handles[$tag] = $fh;
    }

    $counts = array_fill_keys($recordTags, 0);

    $reader = new XMLReader();
    $reader->open($inputPath);

    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || !isset($handles[$reader->name])) {
            continue;
        }
        $tag = $reader->name;
        $record = parseRecord($reader);
        $row = [];
        foreach ($fields[$tag] as $field) {
            $row[] = $record[$field] ?? '';
        }
        fputcsv($handles[$tag], $row, ',', '"', '\\');
        $counts[$tag]++;
    }
    $reader->close();

    foreach ($handles as $fh) {
        fclose($fh);
    }

    foreach ($counts as $tag => $count) {
        echo "  $tag records written: $count -> {$outputs[$tag]}\n";
    }
}

/**
 * Lightweight structural pass: a tag is a "record" if some single instance
 * of its parent contains two or more of it as a direct-child sibling (e.g.
 * <item> under one <items>). Matching by sibling repetition, rather than
 * by raw document-wide tag counts, avoids false positives from leaf field
 * names (like <id>) that happen to recur across different record types.
 *
 * Only the innermost repeating level is kept: a candidate that contains
 * another candidate anywhere beneath it is dropped, since converting it
 * would consume the inner records and leave their CSV empty.
 */
function detectRecordTags(string $inputPath): array
{
    $recordTags = [];
    $descendantsOf = [];
    $stack = [];

    $reader = new XMLReader();
    $reader->open($inputPath);

    while ($reader->read()) {
        if ($reader->nodeType === XMLReader::ELEMENT) {
            $name = $reader->name;

            if (!empty($stack)) {
                $top = count($stack) - 1;
                $stack[$top]['childCounts'][$name] = ($stack[$top]['childCounts'][$name] ?? 0) + 1;
                if ($reader->isEmptyElement) {
                    $stack[$top]['descendants'][$name] = true;
                }
            }

            if (!$reader->isEmptyElement) {
                $stack[] = ['name' => $name, 'childCounts' => [], 'descendants' => []];
            }
        } elseif ($reader->nodeType === XMLReader::END_ELEMENT) {
            $frame = array_pop($stack);
            foreach ($frame['childCounts'] as $childTag => $count) {
                if ($count >= 2) {
                    $recordTags[$childTag] = true;
                }
            }

            // Remember every tag name seen beneath this tag, and pass this
            // subtree's names up to the parent.
            $descendantsOf[$frame['name']] = ($descendantsOf[$frame['name']] ?? []) + $frame['descendants'];
            if (!empty($stack)) {
                $top = count($stack) - 1;
                $stack[$top]['descendants'] += $frame['descendants'] + [$frame['name'] => true];
            }
        }
    }
    $reader->close();

    foreach (array_keys($recordTags) as $tag) {
        // A tag nested inside itself doesn't count as containing another
        // record type.
        $nested = array_intersect_key($descendantsOf[$tag] ?? [], $recordTags);
        unset($nested[$tag]);
        if (!empty($nested)) {
            unset($recordTags[$tag]);
        }
    }

    return array_keys($recordTags);
}

/**
 * Reads the element the reader is currently positioned on and returns its
 * direct child elements as a flat [name => text] map. Advances the reader
 * past the element's end tag.
 */
function parseRecord(XMLReader $reader): array
{
    $record = [];
    $depth = $reader->depth;

    if ($reader->isEmptyElement) {
        return $record;
    }

    $currentField = null;
    while ($reader->read()) {
        if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth) {
            break;
        }
        if ($reader->nodeType === XMLReader::ELEMENT && $reader->depth === $depth + 1) {
            $currentField = $reader->name;
            $record[$currentField] = $record[$currentField] ?? '';
        } elseif ($reader->nodeType === XMLReader::TEXT || $reader->nodeType === XMLReader::CDATA) {
            if ($currentField !== null) {
                $record[$currentField] .= $reader->value;
            }
        }
    }

    return $record;
}
