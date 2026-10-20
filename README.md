# Kabuto

<p align="center">
  <strong>Micro-framework PHP 8.2+ bergaya NestJS dengan arsitektur modular, ORM deklaratif ala Prisma, dan NOL dependensi eksternal.</strong><br>
  <em>"Cast Off" vendor bloat, "Clock Up" performa aplikasi Anda.</em>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP Version" />
  <img src="https://img.shields.io/badge/Dependencies-Zero%20Vendor-success?style=flat-square" alt="Zero Dependencies" />
  <img src="https://img.shields.io/badge/Architecture-NestJS--Style-E0234E?style=flat-square&logo=nestjs&logoColor=white" alt="NestJS Style" />
  <img src="https://img.shields.io/badge/ORM-Prisma--Style-2D3748?style=flat-square&logo=prisma&logoColor=white" alt="Prisma Style" />
  <img src="https://img.shields.io/badge/License-MIT-blue?style=flat-square" alt="License" />
</p>

---

## ✨ Ikhtisar (Overview)

**Kabuto** menghadirkan pola arsitektur modern yang dicintai developer NestJS ke dalam ekosistem PHP 8.2+. Seluruh komponen inti — Dependency Injection Container, HTTP Router, Middleware Pipeline, Validasi DTO, Prisma-Style ORM, dan Test Runner — **ditulis dari nol tanpa pustaka pihak ketiga**.

```php
namespace App\Modules\User;

use Kabuto\Common\Controller;
use Kabuto\Common\Get;
use Kabuto\Common\Post;
use Kabuto\Common\Param;
use Kabuto\Common\Body;
use Kabuto\Common\HttpCode;
use Kabuto\Common\HttpStatus;
use App\Modules\User\Dto\CreateUserDto;

#[Controller('users')]
final class UserController
{
    public function __construct(
        private readonly UserService $userService
    ) {}

    #[Get(':id')]
    public function findOne(#[Param('id')] string $id): array
    {
        return ['data' => $this->userService->findOne($id)];
    }

    #[Post()]
    #[HttpCode(HttpStatus::CREATED)]
    public function create(#[Body()] CreateUserDto $dto): array
    {
        return ['data' => $this->userService->create($dto)];
    }
}
```

---

## 🚀 Fitur Unggulan

- 🛡️ **Zero Third-Party Vendor Bloat (Cast Off)**: Tidak ada ribuan file vendor eksternal. Performa booting ultra-ringan dan memori minim.
- ⚡ **Kecepatan Tinggi (Clock Up)**: Booting dan ekosistem runtime yang dioptimalkan untuk respons milidetik dengan WorkerServer bawaan.
- 🧩 **NestJS-Style Attributes**: Menggunakan native PHP 8 attributes (`#[Controller]`, `#[Injectable]`, `#[Module]`, `#[Auth]`, `#[Roles]`, `#[Permissions]`, `#[User]`, `#[Cookie]`, `#[Get]`, `#[Post]`, `#[Param]`, `#[Body]`).
- 🔐 **Zero-Dependency JWT Authentication & Authorization**: Engine JWT RFC 7519 HMAC-SHA256 bawaan, proteksi instan lewat atribut `#[Auth]`, `#[Roles]`, dan `#[Permissions]`, dynamic policy Gate (`Gate::define()`, `Gate::allows()`, `Gate::authorize()`), injeksi payload otomatis dengan `#[User]`, serta fallback cookie transparan.
- 🛡️ **Kabuto Security Suite**: Middleware Rate Limiting (in-memory/file lock), CSRF signed tokens, Security Headers (CSP, HSTS, X-Frame), hashing Argon2id/Bcrypt, serta enkripsi simetris AES-256-CBC.
- 🍪 **Cookies & Auto-Encryption**: Dukungan penuh atribut `#[Cookie]`, value object `Cookie` fluent, multiple cookie emission, dan enkripsi otomatis.
- 📦 **File Upload & Storage Engine**: Unggah berkas deklaratif via `#[UploadedFile]`, value object `UploadedFile`, dual disk (`local` & `public`), helper `storage()`, CLI `php kabuto storage:link` (symlink / junction), dan static file route `/storage/*` aman.
- 🗄️ **Prisma-Style Declarative ORM**: Satu file skema (`database/schema.php`) untuk seluruh tabel, relasi, dan migrasi instan via `db:push` dan `db:pull`.
- 🔍 **Validasi DTO Otomatis**: Cukup warisi class `DTO`, tentukan `rules()`, dan parameter method controller divalidasi serta dihidrasi secara otomatis.
- 🛠️ **CLI Scaffolding Lengkap**: Perintah `php kabuto` untuk menghasilkan modul CRUD lengkap (`make:resource`), database seeding (`db:seed`), introspeksi tabel (`db:tables`), hingga inisiasi proyek baru (`new`).
- 📖 **OpenAPI Generator**: Hasilkan `storage/openapi.json` dari route, DTO, parameter, upload, response status, dan authentication attributes melalui `php kabuto openapi:generate`.
- 🧪 **Built-in Test Runner**: Test suite mandiri terintegrasi (`php kabuto test` / `composer test`) dengan opsi `--filter` tanpa memerlukan PHPUnit.

---

## 📋 Prasyarat Sistem

- **PHP 8.2 atau lebih baru**
- Ekstensi PHP: `pdo`, `pdo_sqlite` (atau `pdo_mysql` / `pdo_pgsql`), `openssl`, `mbstring`
- Composer (untuk autoloader PSR-4)

---

## ⚡ Memulai Cepat

```bash
# 1. Clone repository
git clone https://github.com/kabuto-php/kabuto.git kabuto
cd kabuto

# 2. Siapkan environment file (Windows: copy .env.example .env)
cp .env.example .env

# 3. Generate APP_KEY untuk enkripsi
php kabuto key:generate

# 4. Sinkronisasi schema & isi seed data contoh
php kabuto db:push
php kabuto db:seed

# 5. Nyalakan local development server
php kabuto serve
```

Buka browser Anda di `http://localhost:8000`. Anda akan menerima respons JSON:

```json
{
  "name": "Kabuto",
  "status": "ok"
}
```

---

## 🛠️ CLI Cheat Sheet (`php kabuto`)

| Perintah | Alias | Deskripsi |
|---|---|---|
| `php kabuto serve` | `serve` | Menjalankan server HTTP persistent worker performa tinggi (Native Worker Runtime) |
| `php kabuto test` | `t` | Menjalankan seluruh unit & integration test (opsi `--filter=<Name>`) |
| `php kabuto make:resource <Name>` | `resource` | Membuat modul CRUD lengkap (Module, Controller, Service, Repository, DTOs) |
| `php kabuto make:module <Name>` | `module` | Membuat kerangka modul baru |
| `php kabuto make:controller <Module>` | `controller` | Membuat controller baru di dalam modul |
| `php kabuto make:service <Module>` | `service` | Membuat service baru di dalam modul |
| `php kabuto make:repository <Module>` | `repository` | Membuat repository baru di dalam modul |
| `php kabuto make:dto <Module> <Name>` | `dto` | Membuat validation DTO baru |
| `php kabuto make:middleware <Name>` | `middleware` | Membuat middleware baru |
| `php kabuto db:push` | `push` | Sinkronisasi tabel database dari `database/schema.php` |
| `php kabuto db:seed` | `seed` | Mengisi data awal database dari `database/seed.php` |
| `php kabuto db:pull` | `pull` | Reverse-engineer skema database langsung ke `schema.php` |
| `php kabuto db:tables` | `tables` | Menampilkan tabel dan kolom yang terdeteksi di database |
| `php kabuto routes` | `route:list` | Menampilkan seluruh route yang terdaftar |
| `php kabuto storage:link` | `storage:link` | Menghubungkan `public/storage` ke `storage/app/public` (symlink / junction) |
| `php kabuto key:generate` | `key` | Membuat kunci aplikasi baru di `.env` |
| `php kabuto new <ProjectName>` | `init` | Membuat kerangka proyek Kabuto baru dari nol |
| `php kabuto openapi:generate` | - | Menghasilkan spesifikasi OpenAPI 3 dari route terdaftar |
| `php kabuto list --json` | - | Menampilkan daftar command dalam format JSON |
| `php kabuto <command> --quiet` | `-q` | Menjalankan command tanpa output normal |
| `php kabuto queue:work` | - | Memproses job dari database queue |
| `php kabuto queue:failed` | - | Menampilkan job queue yang gagal |
| `php kabuto queue:retry <id>` | - | Memasukkan ulang job yang gagal ke queue |
| `php kabuto make:event <Name>` | - | Membuat event class |
| `php kabuto make:listener <Name>` | - | Membuat event listener class |

---

## 🏛️ Pola Arsitektur Bersih

Kabuto mendorong pemisahan tanggung jawab yang rapi:

```text
Request ──▶ Router ──▶ Middleware Pipeline ──▶ Controller (DTO Validation)
                                                     │
                                                     ▼
                                           Service (Business Logic)
                                                     │
                                                     ▼
                                          Repository (Data Access)
                                                     │
                                                     ▼
                                          Database (Prisma-Style ORM)
```

---

## 🧪 Testing dan Quality Checks

Kabuto memiliki framework pengujian mandiri tanpa dependensi:

```bash
composer test
# atau: php kabuto test
```

Test runner mandiri berjalan tanpa PHPUnit. Jalankan dari direktori `framework/`.

Quality checks yang dipakai CI:

```bash
php tools/validate-composer.php
composer lint
composer style:check
php kabuto test
```

CI juga menjalankan PHPStan dan PHP_CodeSniffer pada runner GitHub. PHPStan baseline dimulai di level 0 untuk penaikan bertahap; tools tersebut bukan dependency runtime framework.

---

## 📚 Situs Dokumentasi Lengkap
 
Dokumentasi lengkap dua bahasa (**Bahasa Indonesia & English**) dibangun menggunakan VitePress yang ultra-ringan dan tersedia di folder `docs/`:
 
```bash
cd docs
npm install
npm run dev        # http://localhost:5173
```
 
Untuk mem-build versi statis produksi:
 
```bash
npm run build
```

Panduan utama tersedia untuk [konfigurasi](../docs/docs/api-reference/config-reference.md), [production checklist](../docs/docs/getting-started/production-checklist.md), [upload security](../docs/docs/features/upload-security.md), dan [troubleshooting](../docs/docs/advanced/troubleshooting.md) dalam locale Indonesia dan English.

---

## 📄 Lisensi

Kabuto dirilis di bawah [Lisensi MIT](LICENSE).
