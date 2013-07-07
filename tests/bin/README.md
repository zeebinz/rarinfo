Use this directory to store any executables needed for the tests. None are added
to the repo by default. Currently these are required for all tests to pass:

On Windows:
- .\unrar\UnRAR.exe
- .\7z\7za.exe

On *nix:
- ./unrar/unrar
- ./7z/7za

Sources:
- [UnRAR](http://www.rarlab.com/rar_add.htm)
- [7za](http://www.7-zip.org/download.html)

Notes:
- For 7za, download Windows commandline version or p7zip package for *nix
  (e.g. on CentOS: add RPMForge, then `yum install p7zip`, then look for
  /usr/libexec/p7zip/7za or similar)
