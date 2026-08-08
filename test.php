<?php
$data = file_get_contents('test.png');
if ($data === false || !str_starts_with($data, "\x89PNG\r\n\x1A\n")) {
    echo "Failed signature\n";
    exit;
}

$offset = 8;
$length = strlen($data);
$foundend = false;

while ($offset + 12 <= $length) {
    $chunklength = unpack('Nlength', substr($data, $offset, 4))['length'];
    $type = substr($data, $offset + 4, 4);
    $end = $offset + 12 + $chunklength;
    if ($end > $length) {
        echo "Failed length on chunk $type\n";
        exit;
    }

    $chunkdata = substr($data, $offset + 8, $chunklength);
    $expectedcrc = substr($data, $offset + 8 + $chunklength, 4);
    $actualcrc = pack('N', crc32($type . $chunkdata));
    
    echo "Chunk: $type, len: $chunklength, expected crc: " . bin2hex($expectedcrc) . " actual: " . bin2hex($actualcrc) . "\n";
    if (!hash_equals($expectedcrc, $actualcrc)) {
        echo "Failed CRC on chunk $type\n";
        exit;
    }
    if ($type === 'acTL') {
        echo "Failed acTL\n";
        exit;
    }

    $offset = $end;
    if ($type === 'IEND') {
        $foundend = true;
        break;
    }
}
echo "Found end: " . ($foundend ? 'true' : 'false') . "\n";
