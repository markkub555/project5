# วิธีเปิดใช้งานเว็บ (Windows + Docker)

## 1. ติดตั้งก่อน (ทำครั้งแรกเท่านั้น)
ติดตั้งให้ครบ 2 อย่าง

- Docker Desktop for Windows
- Git

ติดตั้งเสร็จแล้วให้เปิด Docker Desktop และรอจนขึ้นว่า `Docker is running`

## 2. เอาไฟล์โปรเจกต์มาไว้ในเครื่อง
ตัวอย่างเช่นไว้ที่ Desktop

```powershell
Desktop\project5
```

## 3. เปิด Terminal
วิธีที่ง่ายสุด:

- คลิกขวาที่โฟลเดอร์โปรเจกต์
- กด `Open in Terminal`

## 4. เข้าไปในโฟลเดอร์โปรเจกต์
ถ้าใช้ Command Prompt:

```cmd
cd Desktop\project5
```

ถ้าใช้ PowerShell:

```powershell
cd Desktop\project5
```

## 5. ใช้ไฟล์ docker สำหรับ Windows
ถ้าต้องการใช้ชื่อไฟล์ตามมาตรฐาน:

```cmd
copy docker-compose-win.yml docker-compose.yml
```

ถ้าใช้ PowerShell:

```powershell
cp docker-compose-win.yml docker-compose.yml
```

หมายเหตุ:

- โปรเจกต์นี้มี `docker-compose.yml` ให้พร้อมใช้อยู่แล้ว
- ขั้นตอนนี้มีไว้เผื่อต้องการคัดลอกไฟล์ Windows ทับเป็นไฟล์หลัก

## 6. สั่งรันระบบ

```powershell
docker compose up -d --build
```

รอประมาณ 20-40 วินาที

ถ้ามีการแก้โค้ดภายหลัง ให้รัน `docker compose up -d --build` อีกครั้งเพื่ออัปเดตไฟล์ใน container

## 7. เช็กว่ารันสำเร็จไหม

```powershell
docker ps
```

ถ้ารันสำเร็จจะเห็นประมาณนี้

- `php-app`
- `mysql-db`
- `phpmyadmin`

## 8. เข้าใช้งานเว็บ
เปิด Chrome หรือ Edge แล้วเข้า

- เว็บหลัก: `http://localhost:8080/login.php`
- phpMyAdmin: `http://localhost:8081`

## ข้อมูลฐานข้อมูลใน Docker

- Host: `mysql-db`
- Port ภายใน Docker: `3306`
- Port ฝั่งเครื่อง: `3307`
- Database: `register`
- User: `shareduser`
- Password: `1234`
- Root password: `root`

## คำสั่งที่ใช้บ่อย

หยุดระบบ:

```powershell
docker compose down
```

เปิดใหม่:

```powershell
docker compose up -d
```

ดู log:

```powershell
docker compose logs -f
```

รีบิลด์ใหม่:

```powershell
docker compose up -d --build
```

## สรุปสั้นที่สุด

```powershell
docker compose up -d --build
```

แล้วเข้า

- `http://localhost:8080/login.php`
- `http://localhost:8081`
