<?php

declare(strict_types=1);

namespace App;

use DOMDocument;
use ZipArchive;

/**
 * Minimale, dependency-vrije .xlsx-lezer. Begrijpt zowel inline strings (zoals
 * XlsxWriter ze schrijft) als shared strings (xl/sharedStrings.xml) - Excel schrijft
 * shared strings terug zodra een gebruiker het bestand bewerkt en opslaat, dus beide
 * moeten ondersteund worden om een door de gebruiker aangepast bestand te kunnen lezen.
 */
final class XlsxReader
{
    /** @var array<string, array<int, array<int, string>>> */
    private array $sheets = [];

    public function __construct(string $path)
    {
        if (!extension_loaded('zip')) {
            throw new \RuntimeException('De PHP zip-extensie is niet beschikbaar; import is niet mogelijk.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Kan het bestand niet openen. Is het een geldig .xlsx-bestand?');
        }

        $sharedStrings = $this->readSharedStrings($zip);
        [$sheetIdToPath, $sheetNameToId] = $this->readWorkbookStructure($zip);

        foreach ($sheetNameToId as $name => $rId) {
            $sheetPath = $sheetIdToPath[$rId] ?? null;
            if ($sheetPath === null) {
                continue;
            }

            $xml = $zip->getFromName($sheetPath);
            if ($xml === false) {
                continue;
            }

            $this->sheets[$name] = $this->parseSheet($xml, $sharedStrings);
        }

        $zip->close();
    }

    public function hasSheet(string $name): bool
    {
        return isset($this->sheets[$name]);
    }

    /**
     * @return array<int, array<int, string>>
     */
    public function sheet(string $name): array
    {
        return $this->sheets[$name] ?? [];
    }

    /**
     * @return array<int, string>
     */
    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $dom = new DOMDocument();
        $dom->loadXML($xml);

        $strings = [];
        foreach ($dom->getElementsByTagName('si') as $si) {
            $text = '';
            foreach ($si->getElementsByTagName('t') as $t) {
                $text .= $t->textContent;
            }
            $strings[] = $text;
        }

        return $strings;
    }

    /**
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function readWorkbookStructure(ZipArchive $zip): array
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbookXml === false || $relsXml === false) {
            throw new \RuntimeException('Ongeldig Excel-bestand (workbook.xml ontbreekt).');
        }

        $relsDom = new DOMDocument();
        $relsDom->loadXML($relsXml);
        $ridToTarget = [];
        foreach ($relsDom->getElementsByTagName('Relationship') as $rel) {
            $target = ltrim($rel->getAttribute('Target'), '/');
            $ridToTarget[$rel->getAttribute('Id')] = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
        }

        $workbookDom = new DOMDocument();
        $workbookDom->loadXML($workbookXml);

        $sheetNameToId = [];
        $sheetIdToPath = [];
        foreach ($workbookDom->getElementsByTagName('sheet') as $sheet) {
            $name = $sheet->getAttribute('name');
            $rId = $sheet->getAttributeNS(
                'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
                'id'
            );
            $sheetNameToId[$name] = $rId;
            if (isset($ridToTarget[$rId])) {
                $sheetIdToPath[$rId] = $ridToTarget[$rId];
            }
        }

        return [$sheetIdToPath, $sheetNameToId];
    }

    /**
     * @param array<int, string> $sharedStrings
     * @return array<int, array<int, string>>
     */
    private function parseSheet(string $xml, array $sharedStrings): array
    {
        $dom = new DOMDocument();
        $dom->loadXML($xml);

        $rows = [];
        foreach ($dom->getElementsByTagName('row') as $rowEl) {
            $row = [];
            foreach ($rowEl->getElementsByTagName('c') as $cellEl) {
                $ref = $cellEl->getAttribute('r');
                $colIndex = $this->columnIndexFromRef($ref);
                $type = $cellEl->getAttribute('t');

                if ($type === 'inlineStr') {
                    $isEl = $cellEl->getElementsByTagName('is')->item(0);
                    $value = $isEl !== null ? $isEl->textContent : '';
                } else {
                    $vEl = $cellEl->getElementsByTagName('v')->item(0);
                    $raw = $vEl !== null ? $vEl->textContent : '';
                    $value = $type === 's' ? ($sharedStrings[(int) $raw] ?? '') : $raw;
                }

                $row[$colIndex] = $value;
            }

            if ($row === []) {
                continue;
            }

            $maxIndex = max(array_keys($row));
            $ordered = [];
            for ($i = 0; $i <= $maxIndex; $i++) {
                $ordered[] = trim((string) ($row[$i] ?? ''));
            }

            $rows[] = $ordered;
        }

        return $rows;
    }

    private function columnIndexFromRef(string $ref): int
    {
        preg_match('/^([A-Z]+)/', $ref, $matches);
        $letters = $matches[1] ?? 'A';
        $index = 0;
        foreach (str_split($letters) as $char) {
            $index = $index * 26 + (ord($char) - 64);
        }

        return $index - 1;
    }
}
