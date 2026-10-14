<?php
// Standalone test of the archive/crypto pipeline (no Nextcloud needed).
// usage: php tests/core_test.php <outdir> <age-recipient>
spl_autoload_register(function ($c) {
	$p = __DIR__ . '/../lib/' . str_replace(['OCA\\TaggedBackup\\', '\\'], ['', '/'], $c) . '.php';
	if (is_file($p)) require $p;
});
use OCA\TaggedBackup\Archive\{FileSink, GzipSink, Bzip2Sink, TarWriter};
use OCA\TaggedBackup\Crypto\{AgeEncryptSink, AgeRecipients};

[$_, $out, $recipient] = $argv;
$files = [
	'short.txt' => "hello\n",
	str_repeat('d', 120) . '/' . str_repeat('n', 90) . '.txt' => "ustar split\n",
	str_repeat('x', 200) . '/' . str_repeat('y', 200) . '.bin' => random_bytes(70000),
	'ünïcødé/файл.txt' => "unicode\n",
	'empty.txt' => '',
	'exact64k.bin' => str_repeat('A', 65536),
];
foreach (['none', 'gzip', 'bzip2'] as $comp) {
	foreach ([false, true] as $enc) {
		$fn = "$out/test-$comp" . ($enc ? '.age' : '');
		$sink = new FileSink(fopen($fn, 'wb'));
		if ($enc) $sink = new AgeEncryptSink($sink, array_values(AgeRecipients::parse($recipient)));
		if ($comp === 'gzip') $sink = new GzipSink($sink);
		if ($comp === 'bzip2') $sink = new Bzip2Sink($sink);
		$tar = new TarWriter($sink);
		$tar->addDirectory('somedir', 1700000000);
		foreach ($files as $name => $data) $tar->addFileFromString($name, $data, 1700000000);
		$tar->finish();
	}
}
file_put_contents("$out/expected.json", json_encode(array_map('md5', $files), JSON_UNESCAPED_UNICODE));
// edge: exactly 64KiB payload and empty payload through age
foreach (['zero' => '', 'one' => str_repeat('B', 65536), 'two' => str_repeat('C', 131073)] as $k => $d) {
	$s = new AgeEncryptSink(new FileSink(fopen("$out/raw-$k.age", 'wb')), array_values(AgeRecipients::parse($recipient)));
	$s->write($d); $s->close();
	file_put_contents("$out/raw-$k.expected", $d);
}
echo "ok\n";
