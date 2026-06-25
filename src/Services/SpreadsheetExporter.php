<?php

namespace App\Services;

use ZipArchive;

/**
 * Builds a minimal OOXML (.xlsx) workbook from the incoming/outgoing letter
 * rows. Pure string/zip assembly with no DB or network access, so it can be
 * unit-tested directly (the bytes are a valid ZIP containing the expected XML
 * parts). Extracted from api/export.php.
 */
class SpreadsheetExporter
{
    public static function build(array $incoming, array $outgoing): string
    {
        // Sheet 1: incoming
        $inRows = self::headerRow(['Рег. №', 'Дата', 'Организация', 'Категория', 'Номер (ҚК)', 'Тема', 'Примечание']);
        foreach ($incoming as $r) {
            $inRows .= self::row([
                self::cell('Вх.' . ($r['seq'] ?? '')),
                self::cell($r['date'] ?? ''),
                self::cell($r['organization'] ?? ''),
                self::cell($r['category'] ?? 'KK'),
                self::cell($r['kk_number'] ?? ''),
                self::cell($r['subject'] ?? ''),
                self::cell($r['note'] ?? ''),
            ]);
        }

        // Sheet 2: outgoing
        $outRows = self::headerRow(['Порядк. №', 'Дата', 'Исходящий №', 'Организация', 'Тема', 'Примечание', 'Тип']);
        foreach ($outgoing as $r) {
            $outRows .= self::row([
                self::cell('Исх.' . ($r['seq'] ?? '')),
                self::cell($r['date'] ?? ''),
                self::cell($r['outgoing_number'] ?? ''),
                self::cell($r['organization'] ?? ''),
                self::cell($r['subject'] ?? ''),
                self::cell($r['note'] ?? ''),
                self::cell($r['outgoing_type'] ?? 'gov'),
            ]);
        }

        $sheet1Xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . $inRows . '</sheetData></worksheet>';

        $sheet2Xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . $outRows . '</sheetData></worksheet>';

        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>'
            . '<sheet name="Входящие" sheetId="1" r:id="rId1"/>'
            . '<sheet name="Исходящие" sheetId="2" r:id="rId2"/>'
            . '</sheets></workbook>';

        $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
            . '</Relationships>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>';

        $relsRoot = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        // Build ZIP in a temp file (ZipArchive requires a real path, not php://temp)
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        if ($tmp === false) {
            throw new \RuntimeException('Cannot create temp file for XLSX export');
        }
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot open ZipArchive for XLSX export');
        }
        try {
            $zip->addFromString('[Content_Types].xml', $contentTypes);
            $zip->addFromString('_rels/.rels', $relsRoot);
            $zip->addFromString('xl/workbook.xml', $workbookXml);
            $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
            $zip->addFromString('xl/worksheets/sheet1.xml', $sheet1Xml);
            $zip->addFromString('xl/worksheets/sheet2.xml', $sheet2Xml);
            $zip->close();
            $content = file_get_contents($tmp);
            if ($content === false) {
                throw new \RuntimeException('Cannot read XLSX temp file');
            }
            return $content;
        } finally {
            @unlink($tmp);
        }
    }

    private static function escape(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        return htmlspecialchars((string)$v, ENT_XML1, 'UTF-8');
    }

    private static function cell(mixed $v, string $type = 'inlineStr'): string
    {
        $safe = self::escape($v);
        if ($type === 'n') {
            return "<c t=\"n\"><v>{$safe}</v></c>";
        }
        return "<c t=\"inlineStr\"><is><t>{$safe}</t></is></c>";
    }

    private static function row(array $cells): string
    {
        $out = '<row>';
        foreach ($cells as $c) {
            $out .= $c;
        }
        $out .= '</row>';
        return $out;
    }

    private static function headerRow(array $labels): string
    {
        $out = '<row>';
        foreach ($labels as $label) {
            $safe = self::escape($label);
            $out .= "<c t=\"inlineStr\"><is><t>{$safe}</t></is></c>";
        }
        $out .= '</row>';
        return $out;
    }
}
