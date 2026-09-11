# PRD: JDH Auto SEO Publisher Professional AI Router Upgrade

## 1. Ringkasan

Plugin `JDH Auto SEO Publisher` akan ditingkatkan dari generator artikel langsung di WordPress menjadi sistem publishing profesional berbasis AI Router. WordPress tetap menjadi panel kontrol, scheduler, publisher, dan logger. Semua proses kualitas konten, SEO planning, pemilihan angle, validasi narasi, dan strategi gambar akan dikelola oleh Nine Router melalui API yang akan dikonfigurasi terpisah.

Dokumen ini hanya mendefinisikan arsitektur, kebutuhan produk, kontrak data, dan flow teknis. Konfigurasi endpoint Nine Router, Docker, credential, dan mapping node dilakukan setelah ada instruksi lanjutan.

## 2. Problem Saat Ini

### 2.1 Gambar Tidak Relevan

Plugin sekarang membuat query gambar dari keyword secara longgar. Jika Groq gagal menerjemahkan keyword menjadi visual query, fallback menjadi query generik seperti `professional business concept`. Pexels juga memilih foto secara acak dari hasil pencarian, sehingga gambar sering tidak sesuai konteks artikel.

### 2.2 Artikel Ngawur dan Belum SEO Friendly

Prompt artikel belum punya content contract yang ketat. AI diberi instruksi umum seperti humanis, clickbait, unik, dan panjang kata, tetapi tidak diberi search intent, audience, brand guide, struktur SEO, fakta yang boleh/tidak boleh diklaim, entity pendukung, atau tahap audit sebelum publish.

### 2.3 Waktu Tidak Realtime

Manual run memakai AJAX tetapi satu request artikel tetap blocking karena banyak call AI dan proses download gambar. WP-Cron tidak realtime karena hanya berjalan saat WordPress menerima traffic. JS Cron fallback juga baru aktif saat admin membuka halaman plugin.

### 2.4 Mode Keyword Rancu

Server memiliki konsep keyword tracker, tetapi UI manual memilih keyword dari JavaScript yang mulai lagi dari awal saat halaman di-refresh. Ini membuat mode `1 keyword = 1 artikel` tidak konsisten.

### 2.5 Anti Duplicate Lemah

Anti duplicate saat ini hanya membandingkan keyword dengan judul artikel terakhir dalam 1 jam. Ini tidak cukup untuk mencegah keyword sama, topik mirip, judul mirip, atau konten yang hanya diparafrase.

### 2.6 Auto Indexing Tidak Jelas

Plugin memastikan sitemap dapat diakses dan menambahkannya ke `robots.txt`. Ini bukan indexing realtime dan tidak menjamin artikel langsung terindex.

## 3. Tujuan Produk

1. Membuat pipeline artikel yang profesional, SEO friendly, dan terkontrol.
2. Mendukung model SaaS multi-user, di mana setiap website/customer punya guide konten sendiri.
3. Memindahkan AI reasoning ke Nine Router agar proses bisa distandardisasi dan diaudit.
4. Memastikan output AI selalu berbentuk JSON valid yang siap dipublish.
5. Meningkatkan relevansi gambar berdasarkan konteks artikel, bukan keyword mentah.
6. Membuat anti duplicate berbasis keyword, slug, title, content hash, dan optional similarity.
7. Membuat status job lebih jelas: queued, generating, reviewing, image, publishing, done, failed.
8. Menjadikan auto indexing sebagai sitemap and crawl readiness, bukan klaim index instan.
9. Membuat UI plugin yang mudah dipakai user awam tanpa istilah teknis berlebihan.

## 3.1 Keputusan Produk SaaS

Produk ini ditargetkan sebagai layanan SaaS. Karena itu, input seperti niche website, target pembaca, bahasa, dan gaya brand tidak boleh hanya menjadi field setting pasif. Semua input tersebut harus dianalisis oleh sistem lalu dikonversi menjadi `dynamic_content_guide` per website/customer.

Contoh:

- User mengisi niche: `jasa pasang bangunan`.
- Target pembaca: `pemilik rumah, kontraktor kecil, dan orang yang ingin renovasi`.
- Bahasa: `Indonesia`.
- Tone: `edukatif, meyakinkan, tidak terlalu teknis`.

Maka sistem harus membuat guide turunan agar artikel tetap membahas jasa bangunan, renovasi, material, pemasangan, perawatan, pertimbangan teknis ringan, dan kebutuhan customer. Artikel tidak boleh melebar ke otomotif, kesehatan, politik, hiburan, atau topik lain yang tidak relevan.

Dynamic guide ini dikirim bersama `guide.md` setiap kali request ke Nine Router.

## 4. Non-Goals

1. Tidak mengkonfigurasi endpoint Nine Router dalam tahap dokumen ini.
2. Tidak mengganti WordPress sebagai CMS.
3. Tidak menjamin Google langsung mengindex artikel.
4. Tidak membuat artikel medis, legal, atau finansial berisiko tinggi tanpa guardrail tambahan.
5. Tidak langsung menghapus file legacy sebelum ada tahap refactor.

## 5. Arsitektur Target

```text
WordPress Admin UI
  -> Customer/Site Profile
  -> Dynamic Guide Builder
  -> Keyword Manager
  -> Job Manager
  -> Scheduler
  -> Publisher
  -> Logs

WordPress Plugin API Client
  -> POST /chat/completions ke Nine Router secara bertahap
  -> kirim keyword, mode, site context, content guide, duplicate context
  -> terima JSON strategi, outline, dua batch isi, dan tahap final
  -> gabungkan menjadi JSON artikel final

Nine Router
  -> Writing Router
      -> SEO Brief Agent
      -> Angle Explorer Agent
      -> Outline Agent
      -> Draft Writer Agent
      -> SEO Review Agent
      -> Rewrite Agent jika belum lolos atau duplicate
      -> Final JSON Validator
  -> Image Router
      -> Image Prompt Agent
      -> Image Relevance Agent
      -> Image Fallback Agent jika library/Pexels tidak cukup
  -> Final JSON Validator

WordPress Publisher
  -> validasi JSON
  -> cek duplicate lokal
  -> upload/sideload gambar
  -> simpan post draft/publish
  -> set category, tags, SEO meta, schema, featured image
  -> update job status
```

## 6. Komponen WordPress Plugin

### 6.1 Settings

Field baru yang dibutuhkan:

- `router_enabled`: aktif/nonaktifkan Nine Router.
- `writing_router_endpoint`: URL endpoint Nine Router untuk artikel/narasi.
- `image_router_endpoint`: URL endpoint Nine Router khusus image fallback.
- `router_api_key`: API key untuk request ke router.
- `router_model`: model ID combo writing di 9Router, default `Artikel`; bukan ID provider/model langsung.
- `pexels_api_key`: API key Pexels opsional untuk fallback gambar.
- `router_health_check`: tombol admin untuk mengetes koneksi Writing Router dan Image Router sebelum generate.
- `generation_mode`: default `explore_angles`.
- `publish_mode`: default `publish`.
- `site_niche`: niche utama website.
- `target_audience`: target pembaca.
- `content_language`: bahasa artikel, default `id`.
- `brand_tone`: gaya bahasa brand.
- `social_or_external_links`: daftar link sosial media, backlink, atau link pendukung milik customer.
- `forbidden_topics`: default `SARA, politik`.
- `content_quality_min_score`: default 80.
- `image_source_mode`: default `media_library_then_random_hybrid`.
- `watermark_enabled`: default aktif.
- `watermark_text_or_logo`: teks/logo watermark.
- `max_articles_per_run`: default 1, maksimal 5.
- `max_angles_per_keyword`: default 5.

### 6.1.1 Dynamic Guide Builder

Plugin harus menyediakan input sederhana untuk user awam:

- Niche bisnis/website.
- Target pembaca.
- Bahasa artikel.
- Gaya tulisan.
- Link website/sosial media/backlink yang boleh disisipkan.
- Topik yang dilarang.

Setelah disimpan, plugin membuat ringkasan guide otomatis:

- scope topik yang boleh dibahas;
- topik yang harus dihindari;
- contoh angle artikel yang valid;
- contoh angle artikel yang tidak valid;
- tone bahasa;
- aturan CTA/link;
- aturan gambar.

Guide ini menjadi konteks tetap setiap generate artikel.

### 6.2 Keyword Manager

Keyword harus dikelola oleh server, bukan JavaScript.

Status keyword:

- `new`: belum pernah dipakai.
- `queued`: sedang masuk antrean.
- `processing`: sedang diproses.
- `published`: sudah berhasil jadi artikel.
- `failed`: gagal diproses.
- `skipped_duplicate`: dilewati karena duplicate risk.
- `refresh_candidate`: cocok untuk update artikel lama.

Default behavior:

- Satu keyword boleh menghasilkan maksimal 5 artikel dengan angle berbeda.
- Semua angle diproses berurutan.
- Setiap angle harus tetap dalam scope niche dan dynamic guide.
- Jika semua angle untuk keyword sudah habis, keyword tidak dipakai lagi kecuali user reset atau menambah angle.
- Jika duplicate terdeteksi, sistem meminta rewrite angle/content, bukan langsung skip.

Contoh angle valid untuk satu keyword:

- panduan utama;
- checklist;
- kesalahan umum;
- perbandingan;
- FAQ/troubleshooting.

### 6.3 Job Manager

Setiap generate harus menjadi job tersimpan.

Minimal field job:

- `job_id`
- `keyword`
- `generation_mode`
- `status`
- `attempt_count`
- `router_request`
- `router_response`
- `error_message`
- `post_id`
- `created_at`
- `updated_at`

Status job:

- `queued`
- `sending_to_router`
- `generating`
- `reviewing`
- `image_processing`
- `ready_to_publish`
- `publishing`
- `done`
- `failed`

### 6.4 Publisher

Publisher hanya boleh publish setelah:

1. JSON valid.
2. Required field lengkap.
3. Quality score memenuhi minimum.
4. Duplicate check lokal lolos.
5. Content HTML lolos sanitasi.
6. Image tersedia atau fallback policy diterapkan.
7. Watermark diterapkan jika fitur aktif.

Default output adalah langsung `publish`. Jika validasi gagal, sistem tidak publish artikel rusak; job masuk retry/rewrite sampai berhasil atau masuk failed setelah batas teknis retry per cycle.

## 7. Flow Generate Manual

```text
Admin klik Generate
  -> WordPress ambil keyword berikutnya dari server
  -> WordPress tentukan angle berikutnya untuk keyword tersebut
  -> buat job
  -> tahap 1: Writing Router membuat intent, angle, title, dan metadata
  -> tahap 2: Writing Router membuat outline tepat 6 section isi
  -> tahap 3: Writing Router membuat intro dan section 1-2
  -> tahap 4: Writing Router membuat section 3-5 dengan konteks batch sebelumnya
  -> tahap 5: Writing Router membuat section 6, kesimpulan, FAQ, image prompt, dan review
  -> WordPress menggabungkan seluruh output menjadi JSON artikel final
  -> WordPress validasi JSON
  -> WordPress cek duplicate
  -> jika duplicate, WordPress minta rewrite ke router
  -> WordPress ambil gambar dari Media Library
  -> jika tidak cukup relevan, WordPress coba Pexels/Media Library hybrid
  -> jika masih gagal, WordPress panggil Image Router
  -> WordPress pasang watermark
  -> WordPress publish post
  -> WordPress simpan meta SEO, tags, category, schema data
  -> WordPress update keyword status dan job log
```

## 8. Flow Generate Terjadwal

Scheduler tidak boleh bergantung pada JS Cron untuk produksi.

Prioritas implementasi:

1. Server cron memanggil WP-Cron endpoint.
2. WordPress Action Scheduler jika tersedia.
3. WP-Cron native sebagai fallback.
4. JS Cron hanya fallback darurat, harus eksplisit dan diberi peringatan.

## 9. Request Contract ke Nine Router

Contoh payload dari WordPress ke Nine Router:

```json
{
  "job_id": "jdh_20260707_001",
  "keyword": "cara merawat mobil matic",
  "generation_mode": "explore_angles",
  "angle_number": 1,
  "max_angles_per_keyword": 5,
  "site": {
    "name": "Nama Website",
    "url": "https://example.com",
    "niche": "otomotif",
    "target_audience": "pemilik mobil pemula di Indonesia",
    "language": "id",
    "brand_tone": "praktis, jelas, profesional, tidak lebay",
    "forbidden_topics": ["SARA", "politik"]
  },
  "seo": {
    "language": "id",
    "country": "ID",
    "min_words": 1200,
    "max_words": 2200,
    "quality_min_score": 80
  },
  "content_guide": "content guide markdown from guide.md",
  "dynamic_content_guide": "site-specific guide generated from SaaS customer inputs",
  "internal_links": [
    {
      "title": "Tips Servis Mobil Berkala",
      "url": "https://example.com/tips-servis-mobil-berkala"
    }
  ],
  "duplicate_context": {
    "existing_titles": [
      "Cara Mudah Merawat Mobil Matic untuk Pemula"
    ],
    "used_keywords": [
      "cara merawat mobil matic"
    ],
    "similar_posts": []
  },
  "image": {
    "required": true,
    "preferred_style": "realistic editorial photo",
    "orientation": "landscape",
    "source_priority": ["media_library", "pexels", "media_library_random", "image_router"],
    "watermark": {
      "enabled": true,
      "text_or_logo": "Nama Website"
    },
    "avoid": ["abstract concept", "random office photo", "text overlay", "political symbol", "SARA content"]
  },
  "links": {
    "social_or_external_links": [
      {
        "label": "Instagram",
        "url": "https://instagram.com/example"
      }
    ],
    "allow_contextual_backlink": true
  }
}
```

## 10. Response Contract dari Nine Router

Nine Router wajib mengembalikan JSON valid.

```json
{
  "job_id": "jdh_20260707_001",
  "status": "ready",
  "keyword": "cara merawat mobil matic",
  "intent": "informational",
  "angle_number": 1,
  "angle": "panduan perawatan harian dan berkala untuk pemilik mobil matic pemula",
  "title": "Cara Merawat Mobil Matic agar Awet dan Tidak Boros",
  "slug": "cara-merawat-mobil-matic",
  "excerpt": "Panduan praktis merawat mobil matic agar transmisi halus, mesin awet, dan biaya servis tetap terkendali.",
  "meta_description": "Pelajari cara merawat mobil matic agar transmisi awet, mesin tetap halus, dan biaya servis tidak membengkak.",
  "content_html": "<p>...</p><h2>...</h2>",
  "faq": [
    {
      "question": "Kapan oli transmisi mobil matic harus diganti?",
      "answer": "Ikuti rekomendasi buku servis, tetapi banyak kendaraan membutuhkan pengecekan berkala sesuai jarak tempuh dan kondisi pemakaian."
    }
  ],
  "tags": ["mobil matic", "perawatan mobil", "transmisi otomatis"],
  "category": "Otomotif",
  "image": {
    "source_type": "prompt_only",
    "prompt": "realistic editorial photo of a mechanic checking an automatic car transmission in a clean modern garage, natural light, professional automotive maintenance scene",
    "alt": "Mekanik memeriksa transmisi mobil matic di bengkel modern",
    "caption": "Perawatan rutin membantu menjaga performa mobil matic tetap optimal.",
    "url": ""
  },
  "seo_score": 87,
  "quality_score": 89,
  "duplicate_risk": "low",
  "warnings": []
}
```

## 11. Validasi Output

WordPress harus menolak output router jika:

1. JSON tidak valid.
2. `status` bukan `ready`.
3. `title`, `slug`, `meta_description`, atau `content_html` kosong.
4. `quality_score` atau `seo_score` di bawah minimum.
5. `duplicate_risk` bernilai `high` setelah rewrite attempts habis.
6. `content_html` kurang dari 1.200 kata atau lebih dari toleransi 2.400 kata.
7. Artikel tidak memiliki minimal 8 H2: 6 section isi, FAQ, dan kesimpulan.
8. FAQ kurang dari 4 pertanyaan lengkap.
9. Artikel tidak memakai keyword utama secara natural atau terindikasi keyword stuffing.
10. Title atau meta description berada di luar toleransi SEO.
11. Batch memakai heading yang tidak sesuai outline atau mengandung tag terlarang.
12. Gambar tidak punya `prompt` atau `alt`.

Jika gagal validasi, sistem melakukan rewrite/retry. Job hanya masuk `failed` jika retry teknis pada cycle tersebut habis atau router berkali-kali mengembalikan output tidak valid.

## 12. Anti Duplicate

### 12.1 Pre-Generation Check

Sebelum request ke router:

- cek exact keyword di keyword tracker;
- cek slug existing;
- cek title existing;
- cek artikel dengan keyword focus SEO yang sama;
- kirim existing titles dan similar context ke router.

### 12.2 Post-Generation Check

Setelah menerima artikel:

- buat `keyword_hash`;
- buat `title_hash`;
- buat `content_hash`;
- cek title similarity;
- cek slug collision;
- optional: cek embedding/vector similarity jika tersedia;
- jika risk tinggi, wajib minta router rewrite dengan angle berbeda;
- jika masih duplicate setelah beberapa percobaan, job tetap antre retry, bukan publish.

### 12.3 Explore Angles Mode

Jika `generation_mode = explore_angles`, satu keyword boleh dipakai maksimal 5 kali dengan angle berbeda.

Contoh angle:

- pemula
- kesalahan umum
- checklist
- biaya
- studi kasus
- perbandingan
- troubleshooting

Setiap angle harus disimpan agar tidak diulang.

### 12.4 Retry Policy

User-facing rule: retry sampai berhasil.

Implementation rule:

- retry dilakukan melalui queue, bukan infinite loop dalam satu request;
- gunakan backoff agar server tidak terkunci;
- simpan jumlah attempt dan error terakhir;
- duplicate rewrite tidak dihitung sebagai kegagalan fatal selama router masih memberi output valid;
- admin hanya melihat status sederhana: berhasil, sedang diproses, atau gagal sementara.

## 13. Image Quality Policy

Gambar harus dibuat dari konteks artikel, bukan keyword mentah.

Router wajib menghasilkan:

- image prompt konkret;
- alt text SEO;
- caption;
- style;
- negative prompt atau avoid list.

Prompt gambar harus:

- menggambarkan scene nyata;
- sesuai audience dan niche;
- tidak abstrak;
- tidak memakai teks overlay;
- tidak memakai logo brand tanpa izin;
- tidak memilih foto random jika tidak relevan.

Fallback image:

1. Media Library dengan pencarian relevan.
2. Pexels berdasarkan image prompt ringkas.
3. Media Library random terfilter category/niche.
4. Image Router untuk membuat/generate arahan image jika Pexels tidak memungkinkan.
5. Placeholder hanya jika sistem gagal total dan publish harus ditahan.

Semua gambar publish wajib diberi watermark jika `watermark_enabled` aktif.

Featured image adalah publish gate wajib. Post dibuat sebagai draft sementara, featured image dipasang dan diverifikasi, kemudian post baru diubah menjadi `publish`. Jika thumbnail gagal dipasang, draft sementara dihapus dan job masuk retry; artikel tanpa featured image tidak boleh tampil di blog.

## 14. SEO Requirements

Artikel final harus:

- menjawab search intent;
- memiliki title maksimal 60-70 karakter;
- memiliki meta description 120-155 karakter;
- memiliki intro yang menyebut keyword secara natural;
- memiliki tepat 6 H2 isi, 1 H2 FAQ, dan 1 H2 kesimpulan;
- memiliki 4-5 FAQ yang menjawab pertanyaan nyata;
- memiliki 1.200-2.200 kata, dengan toleransi validator sampai 2.400 kata;
- memiliki internal link yang relevan;
- memiliki external link hanya jika diset admin;
- memiliki category dan tags;
- memiliki schema Article;
- optional FAQ schema jika FAQ valid;
- memakai keyword utama dan variasi keyword secara cukup sering tetapi tetap natural;
- tidak keyword stuffing yang merusak kualitas baca;
- tidak membuat klaim spesifik tanpa konteks.
- tidak menyebut harga kecuali user secara eksplisit mengizinkan.
- boleh memakai hook yang kuat selama tidak misleading.
- boleh menyisipkan backlink, link sosial media, atau link pendukung yang diberikan user secara natural.

## 15. Auto Indexing Policy

Plugin tidak boleh menyebut proses sebagai jaminan index realtime.

Yang dilakukan:

- pastikan post publish memiliki canonical;
- pastikan sitemap WordPress/Yoast/RankMath/AIOSEO valid;
- verifikasi endpoint sitemap setelah publish;
- internal link otomatis;
- schema Article dan optional FAQ;
- log URL yang siap crawl.

Integrasi lanjutan:

- Google Search Console reporting;
- URL inspection status jika tersedia;
- Indexing API hanya untuk tipe konten yang sesuai kebijakan Google.

## 16. UI Admin Target

Halaman admin baru sebaiknya memiliki:

- onboarding wizard sederhana;
- tab Profil Website;
- tab Keyword;
- tab Generate Artikel;
- tab Gambar & Watermark;
- tab Riwayat;
- tab Pengaturan Lanjutan yang disembunyikan default;
- preview guide otomatis dalam bahasa sederhana;
- tombol utama: `Buat Artikel Sekarang`;
- status sederhana: berhasil, sedang dibuat, gagal;
- indikator router dengan label awam seperti `Mesin AI aktif`.

UI tidak boleh memaksa user awam memahami istilah teknis seperti endpoint, JSON, cron, nonce, atau webhook di halaman utama. Istilah teknis hanya muncul di mode advanced.

## 17. Acceptance Criteria

1. Admin bisa menyimpan endpoint dan API key router di mode advanced.
2. Generate manual membuat job server-side.
3. Keyword dipilih dari server, bukan dari JavaScript page state.
4. Plugin mengirim `guide.md` dan dynamic guide per website ke router.
5. Plugin menerima JSON setiap tahap, menyusun JSON final, dan memvalidasi field wajib.
6. Artikel langsung publish hanya jika quality gate lolos.
7. Satu keyword bisa menghasilkan maksimal 5 artikel beda angle.
8. Duplicate otomatis masuk rewrite, bukan publish.
9. Gambar memiliki prompt, alt, caption, dan watermark yang sesuai artikel.
10. Artikel tidak dapat dipublish tanpa featured image yang valid dan benar-benar terpasang.
11. SEO meta tersimpan untuk Yoast, RankMath, dan AIOSEO.
12. Log user awam menampilkan berhasil/gagal, judul artikel, dan post ID/link.
13. Topik SARA dan politik ditolak atau dialihkan menjadi failed/needs_review.

## 18. Tahapan Implementasi

### Phase 1: Foundation

- Tambah `PRD.md` dan `guide.md`.
- Definisikan router request/response schema.
- Tambah setting router di plugin.

### Phase 2: Job and Keyword Manager

- Pindahkan pemilihan keyword ke server.
- Tambah tabel/option job log.
- Tambah status keyword.

### Phase 3: Router Integration

- Kirim request ke Nine Router.
- Validasi response JSON.
- Simpan raw request/response.

### Phase 4: Publisher Refactor

- Publish dari JSON final.
- Set SEO meta, category, tags, image.
- Tambah fallback draft jika image gagal.

### Phase 5: Quality and Duplicate Guard

- Tambah hash dan duplicate checks.
- Tambah quality gate.
- Tambah rewrite/retry policy.

### Phase 6: Scheduler and Monitoring

- Rapikan scheduler.
- Tambah status router.
- Tambah logs dan retry.
