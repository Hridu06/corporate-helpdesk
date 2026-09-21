<?php

namespace Tests\Feature\Attachments;

use App\Support\Attachments\AttachmentInspector;
use App\Support\Attachments\AttachmentRejected;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

class AttachmentInspectorTest extends TestCase
{
    /** @var list<string> */
    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function upload(string $bytes, string $clientName, ?string $clientMime = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($path, $bytes);
        $this->temp[] = $path;

        return new UploadedFile($path, $clientName, $clientMime, null, true);
    }

    private function png(int $w = 4, int $h = 4): string
    {
        ob_start();
        imagepng(imagecreatetruecolor($w, $h));

        return (string) ob_get_clean();
    }

    private function jpeg(): string
    {
        ob_start();
        imagejpeg(imagecreatetruecolor(4, 4));

        return (string) ob_get_clean();
    }

    /** @param array<string,string> $entries */
    private function zip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $this->temp[] = $path;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return file_get_contents($path);
    }

    private function docx(array $extra = []): string
    {
        return $this->zip(['[Content_Types].xml' => '<Types/>', 'word/document.xml' => '<w:document/>'] + $extra);
    }

    private function rejects(UploadedFile $file, ?string $containing = null): void
    {
        try {
            (new AttachmentInspector)->inspect($file);
        } catch (AttachmentRejected $e) {
            if ($containing) {
                $this->assertStringContainsString($containing, $e->getMessage());
            }
            $this->assertTrue(true);

            return;
        }
        $this->fail('The file was accepted but should have been rejected.');
    }

    public function test_valid_files_are_accepted_with_server_detected_details(): void
    {
        $inspector = new AttachmentInspector;

        $png = $inspector->inspect($this->upload($this->png(), 'Shot.PNG', 'application/x-evil'));
        $this->assertSame('png', $png['extension']);
        $this->assertSame('image/png', $png['mime_type']); // detected, not the client's claim
        $this->assertSame(hash('sha256', $this->png()), $png['sha256']);

        $this->assertSame('image/jpeg', $inspector->inspect($this->upload($this->jpeg(), 'a.jpg'))['mime_type']);
        $this->assertSame('application/pdf', $inspector->inspect($this->upload("%PDF-1.4\n%%EOF", 'a.pdf'))['mime_type']);
        $this->assertSame('text/plain', $inspector->inspect($this->upload("hello\nworld", 'a.txt'))['mime_type']);
        $this->assertSame('text/csv', $inspector->inspect($this->upload("a,b\n1,2\n", 'a.csv'))['mime_type']);
        $this->assertStringContainsString('wordprocessingml', $inspector->inspect($this->upload($this->docx(), 'a.docx'))['mime_type']);
        $this->assertStringContainsString('spreadsheetml', $inspector->inspect($this->upload(
            $this->zip(['[Content_Types].xml' => '<Types/>', 'xl/workbook.xml' => '<workbook/>']),
            'a.xlsx',
        ))['mime_type']);
    }

    public function test_disallowed_extensions_are_rejected_even_with_harmless_content(): void
    {
        foreach (['a.php', 'a.exe', 'a.svg', 'a.html', 'a.js', 'a.sh', 'a.bat', 'a.zip', 'a.docm', 'a.xlsm', 'a.phtml', 'a', 'a.'] as $name) {
            $this->rejects($this->upload('plain text', $name, 'text/plain'), 'not allowed');
        }
    }

    public function test_content_must_match_the_extension(): void
    {
        $this->rejects($this->upload("<?php echo 'x';", 'shell.png', 'image/png'), 'does not match');
        $this->rejects($this->upload($this->png(), 'photo.pdf'), 'does not match');
        $this->rejects($this->upload("MZ\x90\x00\x03", 'setup.txt', 'text/plain'));
        $this->rejects($this->upload('<html><script>alert(1)</script></html>', 'page.png'), 'does not match');
        $this->rejects($this->upload($this->png(), 'photo.jpg'), 'does not match'); // a png named .jpg
    }

    public function test_double_extensions_are_judged_by_the_last_one_and_the_content(): void
    {
        $this->rejects($this->upload('<?php echo 1;', 'evil.php.png'), 'does not match');
        $this->rejects($this->upload($this->png(), 'evil.png.php'), 'not allowed');
        $this->assertSame('png', (new AttachmentInspector)->inspect($this->upload($this->png(), 'report.php.png'))['extension']);
    }

    public function test_empty_and_oversize_files_are_rejected(): void
    {
        $this->rejects($this->upload('', 'a.txt'), 'empty');

        config(['helpdesk.attachments.max_size_kb' => 1]);
        $this->rejects($this->upload(str_repeat('a', 1025), 'a.txt'), 'larger than');
    }

    public function test_broken_or_bomb_like_images_are_rejected(): void
    {
        $this->rejects($this->upload(substr($this->png(), 0, 20), 'a.png'));

        config(['helpdesk.attachments.max_image_pixels' => 10]);
        $this->rejects($this->upload($this->png(4, 4), 'a.png'), 'dimensions');
    }

    public function test_very_short_text_files_are_accepted_but_binary_lookalikes_are_not(): void
    {
        // libmagic reports "application/octet-stream" for one-character files.
        $this->assertSame('text/plain', (new AttachmentInspector)->inspect($this->upload('1', 'a.txt'))['mime_type']);
        $this->assertSame('text/csv', (new AttachmentInspector)->inspect($this->upload('x', 'a.csv'))['mime_type']);
        $this->rejects($this->upload("\x01\x02", 'a.txt'), 'does not match');
    }

    public function test_content_libmagic_cannot_analyse_is_rejected_cleanly_not_with_an_error(): void
    {
        // On Windows finfo errors out on this; it must be a normal rejection, not an exception (500).
        $script = "<?php system(\$_GET['c']); ?>";

        $this->rejects($this->upload($script, 'shell.png'), 'does not match');
        $this->rejects($this->upload($script, 'shell.pdf'), 'does not match');
        $this->rejects($this->upload($script, 'shell.docx'), 'does not match');
        // As plain text it is harmless, but security software (e.g. Windows Defender) may block reading
        // such a file at all. Either way the outcome must be a clean answer, never an exception.
        try {
            $this->assertSame('text/plain', (new AttachmentInspector)->inspect($this->upload($script, 'snippet.txt'))['mime_type']);
        } catch (AttachmentRejected $e) {
            $this->assertNotSame('', $e->getMessage());
        }
    }

    public function test_text_files_with_binary_data_are_rejected(): void
    {
        $this->rejects($this->upload("text\0binary", 'a.txt'));
    }

    public function test_office_files_must_be_real_and_macro_free(): void
    {
        $this->rejects($this->upload('not a zip at all', 'a.docx'));
        $this->rejects($this->upload($this->zip(['readme.txt' => 'x']), 'a.docx'), 'not valid');
        $this->rejects($this->upload($this->docx(['word/vbaProject.bin' => 'macro']), 'a.docx'), 'macros');
        $this->rejects($this->upload($this->docx(['../escape.txt' => 'x']), 'a.docx'), 'not valid');

        config(['helpdesk.attachments.max_unpacked_bytes' => 5]);
        $this->rejects($this->upload($this->docx(), 'a.docx'), 'unpacked');
    }

    public function test_display_names_are_made_safe(): void
    {
        $clean = fn (string $n, string $e = 'png') => AttachmentInspector::cleanName($n, $e);

        $this->assertSame('shot.png', $clean('shot.png'));
        $this->assertSame('passwd.png', $clean('../../etc/passwd.png'));
        $this->assertSame('a.png', $clean('C:\\Users\\x\\a.png'));
        $this->assertSame('png.png', $clean('....png')); // leading dots are stripped, so no hidden dotfile
        $this->assertSame('report.png', $clean("rep\0ort\n.png"));
        // Right-to-left override used to make "gpj.exe" look like "exe.jpg".
        $this->assertSame('reportexe.png', $clean("report\u{202E}exe.png"));
        $this->assertSame('a b.png', $clean('a   b.png'));
        $this->assertSame('ab.png', $clean('a<b>.png'));
        // The extension shown is the verified one, whatever the client called it.
        $this->assertSame('report.pdf', $clean('report.exe', 'pdf'));

        $long = $clean(str_repeat('x', 500).'.png');
        $this->assertSame(120, mb_strlen($long));
        $this->assertStringEndsWith('.png', $long);
    }
}
