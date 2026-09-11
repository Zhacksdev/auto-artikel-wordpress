# Nine Router Integration Guide

Dokumen ini menjelaskan kontrak integrasi antara plugin WordPress `JDH Auto SEO Publisher` dan Nine Router yang berjalan di Docker.

## 1. Endpoint yang Dibutuhkan

Plugin mendukung dua mode integrasi:

- `OpenAI Compatible /v1`: untuk endpoint base seperti `http://100.104.182.65:20128/v1`.
- `Webhook JSON`: untuk workflow webhook custom.

Plugin mendukung dua endpoint:

- `Writing Router Endpoint`: wajib jika Nine Router aktif.
- `Image Router Endpoint`: opsional untuk mode webhook lama. Pada mode OpenAI Compatible, plugin memakai `Writing Router Endpoint` sebagai base `/v1` dan memanggil `/v1/images/generations`.

Contoh:

```text
http://localhost:5678/webhook/jdh-writing
http://localhost:5678/webhook/jdh-image
```

Contoh OpenAI-compatible:

```text
http://100.104.182.65:20128/v1
```

Jika WordPress berjalan di container berbeda, jangan pakai `localhost` kecuali endpoint memang bisa diakses dari container WordPress. Gunakan host Docker yang bisa dijangkau WordPress, misalnya nama service Docker Compose atau IP host.

## 2. Authentication

Jika `Router API Key` diisi di plugin, plugin mengirim header:

```http
Authorization: Bearer <router_api_key>
Content-Type: application/json
```

Jika kosong, plugin hanya mengirim:

```http
Content-Type: application/json
```

## 3. Health Check

Tombol `Tes Router` di admin plugin mengirim payload ringan ke endpoint router.

Untuk mode `OpenAI Compatible /v1`, plugin melakukan:

```http
GET /v1/models
GET /v1/models/image
```

Jika HTTP status 2xx, router dianggap reachable. Plugin juga memberi warning jika combo artikel tidak terlihat di `/models` atau `Model Image Router` tidak terlihat di `/models/image`.

Untuk mode `Webhook JSON`, plugin mengirim payload berikut.

Payload:

```json
{
  "type": "health_check",
  "source": "jdh-auto-seo-publisher",
  "router_kind": "writing",
  "timestamp": "2026-07-08T00:00:00+00:00",
  "expected_response": {
    "ok": true
  }
}
```

Response yang disarankan:

```json
{
  "ok": true,
  "router": "writing",
  "message": "ready"
}
```

Plugin menganggap endpoint reachable jika HTTP status 2xx. JSON lebih disarankan agar log lebih jelas.

## 4. Writing Router Request

### 4.1 Mode OpenAI Compatible /v1

Untuk endpoint base `/v1`, plugin akan memanggil:

```http
POST /v1/chat/completions
```

Body:

```json
{
  "model": "Artikel",
  "messages": [
    {
      "role": "system",
      "content": "Anda adalah mesin editorial SEO untuk JDH Auto SEO Publisher. Balas dengan tepat satu objek JSON valid."
    },
    {
      "role": "user",
      "content": "Request JSON lengkap dari plugin"
    }
  ],
  "temperature": 0.48,
  "max_tokens": 5000,
  "stream": false
}
```

Satu artikel memakai lima request `/chat/completions` secara berurutan:

1. `strategi judul`: intent, angle, title, slug, excerpt, meta description, category, dan tags.
2. `outline`: tepat 6 section isi, related terms, serta pertanyaan FAQ rujukan.
3. `batch 1`: intro dan section 1-2 dalam `content_html`.
4. `batch 2`: section 3-5 dalam `content_html`, disertai tail batch pertama agar transisinya tidak mengulang.
5. `final`: section 6, Kesimpulan, FAQ, image brief, skor SEO/kualitas, dan duplicate risk.

Content dari `choices[0].message.content` pada setiap request harus berupa satu objek JSON sesuai schema yang tertulis dalam prompt tahap tersebut. Plugin menggabungkan hasil lima tahap menjadi kontrak artikel final pada bagian 5. Jika satu tahap gagal, attempt artikel dihentikan dan pipeline diulang dengan `rewrite_reason`.

Field `model` untuk writing berisi **model ID combo 9Router**, bukan ID model provider. Contoh combo ID: `Artikel`. Di dalam 9Router, combo `Artikel` menentukan urutan model pertama, fallback kedua, dan model berikutnya jika provider sebelumnya gagal. Plugin tidak memilih provider atau urutan fallback.

### 4.2 Mode Webhook JSON

Saat generate artikel, plugin mengirim request seperti ini:

```json
{
  "job_id": "jdh_20260708_010203_abc123",
  "keyword": "jasa pasang baja ringan",
  "generation_mode": "explore_angles",
  "angle_number": 1,
  "max_angles_per_keyword": 5,
  "attempt": 1,
  "rewrite_reason": "",
  "site": {
    "name": "Nama Website",
    "url": "https://example.com/",
    "niche": "jasa pasang bangunan",
    "target_audience": "pemilik rumah yang ingin renovasi",
    "language": "id",
    "brand_tone": "edukatif, meyakinkan, mudah dipahami",
    "forbidden_topics": ["SARA", "politik"]
  },
  "seo": {
    "language": "id",
    "country": "ID",
    "min_words": 1200,
    "max_words": 2200,
    "quality_min_score": 80
  },
  "content_guide": "isi guide.md",
  "dynamic_content_guide": "guide khusus website/customer",
  "internal_links": [
    {
      "title": "Judul artikel lama",
      "url": "https://example.com/judul-artikel-lama/"
    }
  ],
  "duplicate_context": {
    "existing_titles": [],
    "used_keywords": [],
    "similar_posts": []
  },
  "links": {
    "social_or_external_links": [
      {
        "label": "Instagram",
        "url": "https://instagram.com/example"
      }
    ],
    "primary_external_url": "https://example.com/kontak",
    "allow_contextual_backlink": true
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
  }
}
```

## 5. Kontrak Artikel Final

Pada mode OpenAI-compatible, kontrak ini disusun oleh plugin dari hasil lima tahap. Pada mode Webhook JSON, router harus membalas kontrak ini secara langsung tanpa teks tambahan di luar JSON.

```json
{
  "job_id": "jdh_20260708_010203_abc123",
  "status": "ready",
  "keyword": "jasa pasang baja ringan",
  "intent": "commercial",
  "angle_number": 1,
  "max_angles_per_keyword": 5,
  "angle": "panduan memilih jasa pasang baja ringan untuk pemilik rumah",
  "title": "Jasa Pasang Baja Ringan: Panduan Memilih yang Tepat",
  "slug": "jasa-pasang-baja-ringan-panduan-memilih",
  "excerpt": "Panduan praktis memilih jasa pasang baja ringan agar hasil pemasangan rapi, aman, dan sesuai kebutuhan rumah.",
  "meta_description": "Pelajari cara memilih jasa pasang baja ringan yang rapi, aman, dan sesuai kebutuhan renovasi rumah Anda.",
  "content_html": "<p>...</p><h2>...</h2>",
  "faq": [
    {
      "question": "Apa yang perlu dicek sebelum memilih jasa pasang baja ringan?",
      "answer": "Cek pengalaman, alur kerja, material yang digunakan, dan komunikasi penyedia jasa."
    },
    {
      "question": "Bagaimana proses pemasangan baja ringan yang rapi?",
      "answer": "Proses dimulai dari pengukuran, perencanaan rangka, pemasangan sesuai titik tumpu, lalu pemeriksaan ulang sambungan dan kerataan."
    },
    {
      "question": "Material apa yang perlu diperhatikan sebelum pemasangan?",
      "answer": "Perhatikan spesifikasi rangka, kondisi material, kelengkapan sambungan, serta kesesuaiannya dengan desain dan kebutuhan bangunan."
    },
    {
      "question": "Kapan pemasangan perlu diperiksa kembali?",
      "answer": "Lakukan pemeriksaan setelah rangka terpasang dan sebelum tahap penutup atap agar posisi, sambungan, dan bagian yang perlu dikoreksi masih mudah ditangani."
    }
  ],
  "tags": ["jasa pasang baja ringan", "renovasi rumah", "rangka atap"],
  "category": "Jasa Bangunan",
  "image": {
    "source_type": "prompt_only",
    "prompt": "realistic editorial photo of construction workers installing lightweight steel roof framing on a residential house, clean daylight, professional building service scene",
    "alt": "Pekerja memasang rangka atap baja ringan pada rumah",
    "caption": "Pemasangan baja ringan membutuhkan perencanaan dan tenaga kerja yang tepat.",
    "url": "",
    "watermark_required": true
  },
  "seo_score": 85,
  "quality_score": 88,
  "duplicate_risk": "low",
  "warnings": []
}
```

## 6. Validasi Plugin

Plugin akan menolak atau retry response jika:

- JSON tidak valid.
- `status` bukan `ready`.
- `title`, `slug`, `meta_description`, `content_html`, `keyword`, `angle`, atau `image` kosong.
- `quality_score` atau `seo_score` di bawah minimum.
- `duplicate_risk` bernilai `high`.
- artikel kurang dari 1.200 kata atau lebih dari 2.400 kata.
- artikel kurang dari 8 H2.
- FAQ kurang dari 4 pertanyaan lengkap.
- title atau meta description berada di luar toleransi panjang SEO.
- keyword utama muncul kurang dari 3 kali atau lebih dari 18 kali di isi artikel.
- batch tidak memakai heading sesuai outline atau mengandung H1/script/style/iframe.
- image prompt/alt kosong.
- featured image gagal diperoleh atau attachment gagal dipasang sebagai thumbnail.
- topik mengandung SARA/politik atau topik dilarang.
- slug/title/content terlalu mirip dengan artikel lama.

Jika gagal, plugin mengulang seluruh pipeline dengan `attempt` naik dan `rewrite_reason` berisi penyebab. Batas saat ini tiga attempt per job.

## 7. Image Router Request

Image Router hanya dipanggil jika Media Library dan Pexels tidak menghasilkan gambar yang cukup.

### 7.1 Mode OpenAI Compatible /v1

Plugin mengikuti skill `9router-image`:

```text
https://raw.githubusercontent.com/decolua/9router/refs/heads/master/skills/9router-image/SKILL.md
```

Discover image model:

```http
GET /v1/models/image
```

Generate image:

```http
POST /v1/images/generations
```

Body:

```json
{
  "model": "gemini/gemini-3.1-flash-image-preview",
  "prompt": "realistic editorial photo of construction workers installing lightweight steel roof framing on a residential house",
  "size": "1792x1024",
  "n": 1,
  "response_format": "url"
}
```

Plugin mendukung response:

```json
{
  "created": 1735000000,
  "data": [
    {
      "url": "https://..."
    }
  ]
}
```

Plugin juga mendukung `b64_json` jika provider mengembalikan base64.

### 7.2 Mode Webhook JSON

```json
{
  "keyword": "jasa pasang baja ringan",
  "title": "Jasa Pasang Baja Ringan: Panduan Memilih yang Tepat",
  "image": {
    "source_type": "prompt_only",
    "prompt": "realistic editorial photo of construction workers installing lightweight steel roof framing on a residential house",
    "alt": "Pekerja memasang rangka atap baja ringan pada rumah",
    "caption": "Pemasangan baja ringan membutuhkan perencanaan dan tenaga kerja yang tepat.",
    "url": "",
    "watermark_required": true
  },
  "dynamic_content_guide": "guide khusus website/customer"
}
```

## 8. Image Router Response

Response minimal:

```json
{
  "ok": true,
  "url": "https://example.com/generated-image.jpg"
}
```

Plugin akan melakukan sideload URL tersebut ke Media Library, lalu memberi watermark jika fitur watermark aktif.

## 9. Mapping Node Nine Router

Rekomendasi node untuk Writing Router:

1. Webhook Trigger.
2. Auth check jika API key dipakai.
3. Jika `type = health_check`, langsung return `{ "ok": true, "router": "writing" }`.
4. Build SEO brief dari `keyword`, `site`, `content_guide`, dan `dynamic_content_guide`.
5. Pilih angle berdasarkan `angle_number`.
6. Generate outline.
7. Generate artikel HTML.
8. Review SEO dan kualitas.
9. Rewrite jika score rendah atau duplicate context berisiko.
10. Generate image prompt.
11. Final JSON validator.
12. Respond to Webhook.

Rekomendasi node untuk Image Router:

1. Webhook Trigger.
2. Auth check jika API key dipakai.
3. Jika `type = health_check`, return `{ "ok": true, "router": "image" }`.
4. Ambil `image.prompt`.
5. Generate image atau pilih provider image.
6. Return URL gambar publik.

## 10. Checklist Integrasi

1. Pastikan WordPress bisa mengakses endpoint router.
2. Pilih `Mode Router`.
3. Untuk endpoint yang diberikan sekarang, pilih `OpenAI Compatible /v1`.
4. Isi `Writing Router Endpoint` dengan base URL `/v1`.
5. Isi `Router API Key`.
6. Isi `Combo Artikel 9Router` dengan model ID combo, misalnya `Artikel`.
7. Isi `Image Router Endpoint` jika sudah tersedia.
8. Centang `Gunakan Nine Router`.
9. Klik `Simpan Pengaturan`.
10. Klik `Tes Router`.
11. Jika test OK, isi profil website dan keyword.
12. Klik `Buat Artikel Sekarang`.

## 10.1 Cron Otomatis Harian

Plugin menjadwalkan hook WordPress:

```text
jdh_daily_article_generation
```

Jam eksekusi bisa diatur dari field `Jam Generate Harian` di halaman plugin. Setelah pengaturan disimpan, plugin akan reschedule event harian.

Untuk produksi, tetap disarankan membuat cron hosting asli agar WP-Cron terpanggil stabil:

```bash
*/15 * * * * curl -s https://domainkamu.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

Tanpa cron hosting, WP-Cron hanya berjalan saat website menerima traffic.

## 10.2 Relevansi Gambar

Saat mencari/generate gambar, plugin membangun prompt gambar dari:

- keyword utama;
- judul artikel;
- niche website;
- target audience;
- prompt gambar dari Writing Router;
- larangan text overlay, logo, politik, dan SARA.

Urutan sumber gambar:

1. Media Library yang relevan.
2. Pexels jika API key tersedia.
3. Media Library random yang tetap lolos filter relevansi.
4. 9Router `/v1/images/generations`.
5. Pollinations fallback.

Media Library tidak lagi dipakai sembarang; attachment harus punya kecocokan term di title, alt, caption, atau filename.

## 11. Setup untuk Endpoint Saat Ini

Gunakan pengaturan plugin berikut:

```text
Mode Router: OpenAI Compatible /v1
Writing Router Endpoint: http://100.104.182.65:20128/v1
Router API Key: isi dengan API key yang diberikan
Combo Artikel 9Router: Artikel
Model Image Router: gemini/gemini-3.1-flash-image-preview
Image Router Endpoint: kosong untuk mode OpenAI Compatible
```

Jangan hardcode API key ke file plugin. Simpan melalui halaman admin WordPress agar bisa diganti per instalasi/customer.
