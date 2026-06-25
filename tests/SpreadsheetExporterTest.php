<?php

namespace Tests;

use App\Services\SpreadsheetExporter;
use PHPUnit\Framework\TestCase;

/**
 * Unit-tests App\Services\SpreadsheetExporter — the OOXML (.xlsx) builder
 * extracted from api/export.php. The output must be a valid ZIP package
 * containing the expected workbook parts, with row data (and XML-escaped
 * special characters) present in the worksheet streams.
 */
class SpreadsheetExporterTest extends TestCase
{
    private function openZip(string $bytes): \ZipArchive
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsxtest_');
        file_put_contents($tmp, $bytes);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($tmp) === true, 'output is a valid ZIP');
        // Unlink now; the open handle keeps the inode alive until close().
        @unlink($tmp);
        return $zip;
    }

    public function testProducesValidXlsxPackageWithExpectedParts(): void
    {
        $bytes = SpreadsheetExporter::build([], []);
        $this->assertNotSame('', $bytes);

        $zip = $this->openZip($bytes);
        foreach ([
            '[Content_Types].xml',
            '_rels/.rels',
            'xl/workbook.xml',
            'xl/_rels/workbook.xml.rels',
            'xl/worksheets/sheet1.xml',
            'xl/worksheets/sheet2.xml',
        ] as $part) {
            $this->assertNotFalse($zip->locateName($part), "package contains {$part}");
        }
        // Two named sheets in the workbook.
        $this->assertStringContainsString('name="Входящие"', $zip->getFromName('xl/workbook.xml'));
        $this->assertStringContainsString('name="Исходящие"', $zip->getFromName('xl/workbook.xml'));
        $zip->close();
    }

    public function testRowDataAndXmlEscapingInWorksheets(): void
    {
        $incoming = [[
            'seq' => 7,
            'date' => '2026-01-15',
            'organization' => 'ТОО «А&Б»',
            'category' => 'KK',
            'kk_number' => 'KK-1',
            'subject' => 'Тема <тест>',
            'note' => '',
        ]];
        $outgoing = [[
            'seq' => 3,
            'date' => '2026-02-01',
            'outgoing_number' => 'OUT-9',
            'organization' => 'Госорган',
            'subject' => 'Ответ',
            'note' => 'примечание',
            'outgoing_type' => 'gov',
        ]];

        $zip = $this->openZip(SpreadsheetExporter::build($incoming, $outgoing));
        $sheet1 = $zip->getFromName('xl/worksheets/sheet1.xml');
        $sheet2 = $zip->getFromName('xl/worksheets/sheet2.xml');

        $this->assertStringContainsString('Вх.7', $sheet1);
        $this->assertStringContainsString('ТОО «А&amp;Б»', $sheet1, '& must be XML-escaped');
        $this->assertStringContainsString('Тема &lt;тест&gt;', $sheet1, '< > must be XML-escaped');
        $this->assertStringContainsString('Исх.3', $sheet2);
        $this->assertStringContainsString('OUT-9', $sheet2);
        $zip->close();
    }
}
