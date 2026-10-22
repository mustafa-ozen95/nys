<?php

declare(strict_types=1);

namespace App\Services\Export;

use RuntimeException;
use ZipArchive;

/**
 * Tek sayfalık bir Excel (.xlsx) dosyası üretir.
 *
 * NEDEN KÜTÜPHANE DEĞİL: panelin bağımlılık listesi beş satır ve öyle
 * kalması bilinçli (kurulumun basit kalması bu listeye bağlı). Tam teşekküllü
 * bir tablo kütüphanesi yüzlerce dosya getiriyor; burada gereken ise tek
 * sayfa, tek satır başlık ve düz metin hücreler. xlsx zaten birkaç XML'in
 * zip'i; o birkaç XML aşağıda.
 *
 * NEDEN CSV DEĞİL: Excel, CSV'yi açarken ayracı ve kodlamayı kendi tahmin
 * ediyor. Türkçe Windows'ta virgülle ayrılmış UTF-8 bir dosya tek sütuna
 * yığılıyor ve Ç/Ş/Ğ bozuluyor. xlsx'te tahmin yok.
 *
 * Hücreler "satır içi metin" (inlineStr) olarak yazılır: paylaşılan metin
 * tablosu kurmaya gerek kalmıyor ve her hücre kendi değerini taşıyor. Sayı
 * biçimi de YOK — parola "0012" ise sıfırları korunsun, tarih sanılmasın.
 */
final class Workbook
{
    /**
     * @param  array<int, string>  $basliklar
     * @param  array<int, array<int, string|null>>  $satirlar
     * @return string Dosyanın ham içeriği.
     */
    public static function generate(string $pageName, array $headers, array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx-');

        if ($path === false) {
            throw new RuntimeException('Geçici dosya açılamadı.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Çalışma kitabı yazılamadı.');
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypes());
        $zip->addFromString('_rels/.rels', self::rootRelations());
        $zip->addFromString('xl/workbook.xml', self::book($pageName));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::bookRelations());
        $zip->addFromString('xl/styles.xml', self::formats());
        $zip->addFromString('xl/worksheets/sheet1.xml', self::page($headers, $rows));
        $zip->close();

        $content = (string) file_get_contents($path);

        unlink($path);

        return $content;
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private static function rootRelations(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private static function book(string $pageName): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.self::escape(self::pageName($pageName)).'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    private static function bookRelations(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    /** Tek biçem: kalın başlık satırı (s="1"). */
    private static function formats(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            .'<borders count="1"><border/></borders>'
            .'<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            .'<cellXfs count="2"><xf xfId="0"/><xf fontId="1" applyFont="1" xfId="0"/></cellXfs>'
            .'</styleSheet>';
    }

    /**
     * @param  array<int, string>  $basliklar
     * @param  array<int, array<int, string|null>>  $satirlar
     */
    private static function page(array $headers, array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .self::columnWidths(count($headers))
            .'<sheetData>'
            .self::row(1, $headers, thick: true);

        $no = 2;

        foreach ($rows as $row) {
            $xml .= self::row($no++, array_values($row));
        }

        return $xml.'</sheetData></worksheet>';
    }

    /** Sütunlar okunabilir genişlikte açılsın; hepsi aynı. */
    private static function columnWidths(int $count): string
    {
        return $count === 0 ? '' : '<cols><col min="1" max="'.$count.'" width="26" customWidth="1"/></cols>';
    }

    /** @param  array<int, string|null>  $hucreler */
    private static function row(int $no, array $cells, bool $thick = false): string
    {
        $xml = '<row r="'.$no.'">';

        foreach ($cells as $order => $value) {
            $address = self::columnName($order).$no;
            $format = $thick ? ' s="1"' : '';

            $xml .= '<c r="'.$address.'"'.$format.' t="inlineStr"><is><t xml:space="preserve">'
                .self::escape((string) $value).'</t></is></c>';
        }

        return $xml.'</row>';
    }

    /** 0 => A, 25 => Z, 26 => AA ... */
    private static function columnName(int $order): string
    {
        $name = '';

        for ($n = $order + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26).$name;
        }

        return $name;
    }

    /**
     * Sayfa adı: Excel'in yasakladığı karakterler ve 31 karakter sınırı.
     *
     * Sınırı aşan ya da : \ / ? * [ ] içeren bir ad dosyayı AÇILMAZ yapıyor —
     * hata da vermiyor, Excel "onarılması gereken içerik" diyor.
     */
    private static function pageName(string $name): string
    {
        $clean = (string) preg_replace('#[:\\\\/?*\[\]]#', ' ', $name);

        return mb_substr(trim($clean) === '' ? 'Sayfa1' : trim($clean), 0, 31);
    }

    /**
     * XML kaçışı + DENETİM KARAKTERLERİNİN ATILMASI.
     *
     * XML 1.0 bazı denetim karakterlerini hiç kabul etmiyor; kaçırılmış olsa
     * bile dosyayı bozuyorlar. Parolada böyle bir karakter bulunması olası
     * değil ama değeri her zaman panel üretmiyor (elle de girilebiliyor).
     */
    private static function escape(string $value): string
    {
        $clean = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);

        return htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
