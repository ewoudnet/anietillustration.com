<?php

declare(strict_types=1);

namespace App;

use ZipArchive;

/**
 * Minimale, dependency-vrije .xlsx-schrijver (Office Open XML) via ZipArchive. Ondersteunt
 * meerdere werkbladen met platte tekst/getal-cellen (inline strings, geen shared-strings-tabel
 * nodig bij schrijven) - genoeg voor een leesbare Excel-export.
 */
final class XlsxWriter
{
    /** @var array<string, array<int, array<int, string|int|float|null>>> */
    private array $sheets = [];

    /**
     * @param array<int, array<int, string|int|float|null>> $rows Eerste rij = kopregel.
     */
    public function addSheet(string $name, array $rows): void
    {
        $this->sheets[$name] = $rows;
    }

    public function save(string $path): void
    {
        if (!extension_loaded('zip')) {
            throw new \RuntimeException('De PHP zip-extensie is niet beschikbaar; export naar Excel is niet mogelijk.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Kan het Excel-bestand niet aanmaken.');
        }

        $sheetNames = array_keys($this->sheets);
        $sheetCount = count($sheetNames);

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml($sheetCount));
        $zip->addFromString('_rels/.rels', $this->rootRelsXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml($sheetNames));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml($sheetCount));
        $zip->addFromString('xl/styles.xml', $this->stylesXml());

        $i = 1;
        foreach ($this->sheets as $rows) {
            $zip->addFromString("xl/worksheets/sheet{$i}.xml", $this->sheetXml($rows));
            $i++;
        }

        $zip->close();
    }

    private function contentTypesXml(int $sheetCount): string
    {
        $overrides = '';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" '
                . 'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $overrides
            . '</Types>';
    }

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" '
            . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" '
            . 'Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    /**
     * @param array<int, string> $sheetNames
     */
    private function workbookXml(array $sheetNames): string
    {
        $sheetsXml = '';
        foreach (array_values($sheetNames) as $index => $name) {
            $id = $index + 1;
            $sheetsXml .= '<sheet name="' . $this->escape($name) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheetsXml . '</sheets>'
            . '</workbook>';
    }

    private function workbookRelsXml(int $sheetCount): string
    {
        $rels = '';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $rels .= '<Relationship Id="rId' . $i . '" '
                . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
                . 'Target="worksheets/sheet' . $i . '.xml"/>';
        }
        $stylesId = $sheetCount + 1;
        $rels .= '<Relationship Id="rId' . $stylesId . '" '
            . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels
            . '</Relationships>';
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    /**
     * @param array<int, array<int, string|int|float|null>> $rows
     */
    private function sheetXml(array $rows): string
    {
        $rowsXml = '';
        $rowNum = 1;
        foreach ($rows as $row) {
            $cellsXml = '';
            $colIndex = 0;
            foreach ($row as $value) {
                $ref = $this->columnLetter($colIndex) . $rowNum;
                if ($value === null || $value === '') {
                    $cellsXml .= '<c r="' . $ref . '"/>';
                } elseif (is_int($value) || is_float($value)) {
                    $cellsXml .= '<c r="' . $ref . '"><v>' . $value . '</v></c>';
                } else {
                    $cellsXml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
                        . $this->escape((string) $value) . '</t></is></c>';
                }
                $colIndex++;
            }
            $rowsXml .= '<row r="' . $rowNum . '">' . $cellsXml . '</row>';
            $rowNum++;
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . $rowsXml . '</sheetData>'
            . '</worksheet>';
    }

    private function columnLetter(int $index): string
    {
        $letter = '';
        $index++;
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $index = intdiv($index - $mod, 26);
        }

        return $letter;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
