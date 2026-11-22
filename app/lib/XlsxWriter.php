<?php
declare(strict_types=1);

/** Minimal .xlsx writer: right-to-left sheets, bold header row, text or numeric cells, optional dropdown/validation rules. Requires ext-zip. */
final class XlsxWriter
{
    /** @var list<array{name:string, rows:list<list<mixed>>, widths:list<int>, rules:list<array>}> */
    private array $sheets = [];

    /**
     * $rules: data validation, each ['ref' => 'D2:D1000', 'list' => "'Sheet'!\$A\$2:\$A\$9"] for a dropdown
     * or ['ref' => ..., 'custom' => 'formula'] for a formula check, or neither for a hint only; optional 'title', 'error', 'prompt'.
     */
    public function addSheet(string $name, array $rows, array $widths = [], array $rules = []): void
    {
        $this->sheets[] = ['name' => self::sheetName($name), 'rows' => $rows, 'widths' => $widths, 'rules' => $rules];
    }

    /** The name a sheet gets in the file (for list references to it). */
    public static function sheetName(string $name): string
    {
        return mb_substr(preg_replace('#[\\\\/?*\[\]:]#u', ' ', $name), 0, 31);
    }

    public function save(string $file): void
    {
        $z = new ZipArchive();
        if ($z->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Cannot create xlsx');
        $n = count($this->sheets);
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $wbSheets = '';
        for ($i = 1; $i <= $n; $i++) {
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $wbRels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
            $wbSheets .= '<sheet name="' . self::x($this->sheets[$i - 1]['name']) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
            $z->addFromString("xl/worksheets/sheet$i.xml", $this->sheetXml($this->sheets[$i - 1]));
        }
        $wbRels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $z->addFromString('[Content_Types].xml', $ct . '</Types>');
        $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wbSheets . '</sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
        // style 0 = default, 1 = bold header with fill, 2 = text (@) so codes/phones keep leading zeros
        $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Arial"/></font><font><b/><sz val="11"/><name val="Arial"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFDDE8F5"/></patternFill></fill></fills>'
            . '<borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="3"><xf xfId="0"/><xf xfId="0" fontId="1" fillId="2" applyFont="1" applyFill="1"/><xf xfId="0" numFmtId="49" applyNumberFormat="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
        $z->close();
    }

    private function sheetXml(array $s): string
    {
        $cols = '';
        foreach ($s['widths'] as $i => $w) $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (int)$w . '" style="2" customWidth="1"/>';
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView rightToLeft="1" workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . ($cols ? "<cols>$cols</cols>" : '') . '<sheetData>';
        foreach ($s['rows'] as $ri => $row) {
            $r = $ri + 1;
            $xml .= '<row r="' . $r . '">';
            foreach (array_values($row) as $ci => $v) {
                if ($v === null || $v === '') continue;
                $ref = self::col($ci) . $r;
                $style = $ri === 0 ? ' s="1"' : ' s="2"';
                if ((is_int($v) || is_float($v)) && $ri > 0) $xml .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
                else $xml .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . self::x((string)$v) . '</t></is></c>';
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';
        if ($s['rules']) {
            $xml .= '<dataValidations count="' . count($s['rules']) . '">';
            foreach ($s['rules'] as $v) {
                $type = isset($v['list']) ? 'list' : (isset($v['custom']) ? 'custom' : 'none');
                $xml .= '<dataValidation type="' . $type . '" allowBlank="1" showInputMessage="1" showErrorMessage="' . (empty($v['free']) ? '1' : '0') . '" errorStyle="stop"'
                    . (isset($v['title']) ? ' errorTitle="' . self::x($v['title']) . '" promptTitle="' . self::x($v['title']) . '"' : '')
                    . (isset($v['error']) ? ' error="' . self::x($v['error']) . '"' : '')
                    . (isset($v['prompt']) ? ' prompt="' . self::x($v['prompt']) . '"' : '')
                    . ' sqref="' . self::x($v['ref']) . '">' . ($type === 'none' ? '' : '<formula1>' . self::x($v['list'] ?? $v['custom']) . '</formula1>') . '</dataValidation>';
            }
            $xml .= '</dataValidations>';
        }
        return $xml . '</worksheet>';
    }

    private static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
        return $s;
    }

    private static function x(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s); // characters not allowed in XML
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
