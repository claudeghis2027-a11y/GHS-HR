<?php
declare(strict_types=1);

/** Minimal dependency-free .xlsx reader (values only). Requires ext-zip and ext-simplexml. */
final class XlsxReader
{
    private const MAX_ENTRY = 30 * 1024 * 1024;
    private ZipArchive $zip;
    private array $strings = [];
    /** @var array<string,string> sheet name => zip path */
    private array $sheets = [];

    public function __construct(string $file)
    {
        $this->zip = new ZipArchive();
        if ($this->zip->open($file) !== true) throw new RuntimeException('Not a valid .xlsx file');
        $ss = $this->zip->locateName('xl/sharedStrings.xml') === false ? false : $this->entry('xl/sharedStrings.xml');
        if ($ss !== false) {
            $x = $this->xml($ss);
            foreach ($x->si as $si) $this->strings[] = $this->text($si);
        }
        $rels = [];
        $rx = $this->xml($this->entry('xl/_rels/workbook.xml.rels'));
        foreach ($rx->Relationship as $r) {
            $t = (string)$r['Target'];
            $rels[(string)$r['Id']] = str_starts_with($t, '/') ? ltrim($t, '/') : 'xl/' . $t;
        }
        $wb = $this->xml($this->entry('xl/workbook.xml'));
        foreach ($wb->sheets->sheet as $s) {
            $rid = (string)$s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $this->sheets[(string)$s['name']] = $rels[$rid];
        }
    }

    public function sheetNames(): array { return array_keys($this->sheets); }

    /** @return list<array<int,mixed>> rows of 0-based column => value ('' for empty) */
    public function rows(string $sheet): array
    {
        if (!isset($this->sheets[$sheet])) return [];
        if (!str_starts_with($this->sheets[$sheet], 'xl/')) return [];
        $x = $this->xml($this->entry($this->sheets[$sheet]));
        $out = [];
        foreach ($x->sheetData->row as $row) {
            $r = [];
            foreach ($row->c as $c) {
                $col = self::colIndex((string)$c['r']);
                $t = (string)$c['t'];
                $v = isset($c->v) ? (string)$c->v : null;
                $val = match ($t) {
                    's'         => $this->strings[(int)$v] ?? '',
                    'inlineStr' => $this->text($c->is),
                    'b'         => $v === '1',
                    'str', 'e'  => (string)$v,
                    default     => $v === null ? '' : (is_numeric($v) ? $v + 0 : $v),
                };
                $r[$col] = $val;
            }
            if ($r && array_filter($r, fn($v) => $v !== '' && $v !== null)) $out[] = $r;
        }
        return $out;
    }

    /** Rows as assoc arrays keyed by the header row (row 1). */
    public function records(string $sheet): array
    {
        $rows = $this->rows($sheet);
        if (!$rows) return [];
        $head = array_shift($rows);
        $out = [];
        foreach ($rows as $r) {
            $rec = [];
            foreach ($head as $i => $h) $rec[trim((string)$h)] = $r[$i] ?? '';
            $out[] = $rec;
        }
        return $out;
    }

    private static function colIndex(string $ref): int
    {
        preg_match('/^([A-Z]+)/', $ref, $m);
        $n = 0;
        foreach (str_split($m[1]) as $ch) $n = $n * 26 + (ord($ch) - 64);
        return $n - 1;
    }

    /** Reads one zip entry, refusing oversized ones (zip-bomb guard for uploaded files). */
    private function entry(string $name): string
    {
        $st = $this->zip->statName($name);
        if ($st === false) throw new RuntimeException('Not a valid .xlsx file');
        if ($st['size'] > self::MAX_ENTRY) throw new RuntimeException('Workbook too large');
        return (string)$this->zip->getFromName($name);
    }

    private function text(SimpleXMLElement $si): string
    {
        // <t> directly, or rich-text runs <r><t>…</t></r>; phonetic <rPh> runs are skipped.
        $s = '';
        foreach ($si->children() as $name => $node) {
            if ($name === 't') $s .= (string)$node;
            elseif ($name === 'r') foreach ($node->children() as $n2 => $t) if ($n2 === 't') $s .= (string)$t;
        }
        return $s;
    }

    private function xml(string $s): SimpleXMLElement
    {
        $x = simplexml_load_string($s, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        if ($x === false) throw new RuntimeException('Bad XML in workbook');
        return $x;
    }
}
