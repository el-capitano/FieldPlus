@echo off
echo ========================================================
echo  FieldPlus - Field Visit Survey Web Admin Server
echo  Serving on: http://0.0.0.0:8080
echo  Android Emulator URL: http://10.0.2.2:8080/api
echo ========================================================
cd /d "%~dp0"
php -d upload_max_filesize=128M -d post_max_size=128M -d max_execution_time=300 -d memory_limit=512M -S 0.0.0.0:8080 -t public
pause
