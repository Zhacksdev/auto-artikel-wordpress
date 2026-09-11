# JDH Article Generation Guide

Dokumen ini adalah content contract yang wajib dibaca dan dipakai setiap kali Nine Router membuat artikel untuk plugin JDH Auto SEO Publisher. Tujuannya memastikan artikel tidak ngawur, tetap relevan dengan keyword, SEO friendly, dan siap dipublish ke WordPress.

## 1. Prinsip Utama

1. Artikel harus menjawab search intent keyword, bukan sekadar mengulang keyword.
2. Artikel harus faktual, praktis, dan mudah dipahami pembaca Indonesia.
3. Artikel tidak boleh mengarang data, statistik, harga, aturan, diagnosis, atau klaim sensitif.
4. Artikel harus terasa ditulis untuk manusia, tetapi tetap rapi secara SEO.
5. Artikel harus punya sudut pandang yang jelas dan tidak melompat-lompat.
6. Artikel harus relevan dengan niche website dan target audience.
7. Artikel harus lolos quality review sebelum dikirim ke WordPress.
8. Artikel tidak boleh membahas SARA dan politik.
9. Artikel harus mengikuti dynamic guide dari profil website/customer.
10. Artikel boleh memakai hook kuat, tetapi tidak boleh misleading.

## 2. Input yang Wajib Dipahami

Sebelum menulis, pahami field berikut:

- `keyword`: keyword utama.
- `generation_mode`: umumnya `explore_angles`.
- `angle_number`: urutan angle untuk keyword.
- `max_angles_per_keyword`: maksimal 5.
- `site.niche`: niche website.
- `site.target_audience`: target pembaca.
- `site.language`: bahasa artikel.
- `site.brand_tone`: gaya komunikasi brand.
- `dynamic_content_guide`: guide khusus dari input user SaaS.
- `internal_links`: daftar link internal yang boleh dipakai.
- `social_or_external_links`: daftar backlink, sosial media, atau link pendukung yang boleh dipakai.
- `duplicate_context`: judul, keyword, atau artikel lama yang harus dihindari.
- `image`: preferensi gambar.

Jika input kurang lengkap, tetap hasilkan artikel yang aman dan umum, tetapi tulis warning di field `warnings`.

## 3. Search Intent

Tentukan intent utama sebelum membuat outline.

Jenis intent:

- `informational`: pembaca ingin memahami topik.
- `how_to`: pembaca ingin langkah praktis.
- `commercial`: pembaca membandingkan sebelum membeli/memilih.
- `local`: pembaca mencari konteks lokasi.
- `transactional`: pembaca ingin mengambil tindakan.
- `troubleshooting`: pembaca ingin menyelesaikan masalah.

Artikel harus mengikuti intent tersebut.

Contoh:

- Keyword `cara merawat mobil matic` berarti intent `how_to`.
- Keyword `mobil matic vs manual` berarti intent `commercial/comparison`.
- Keyword `penyebab mobil matic nyentak` berarti intent `troubleshooting`.

## 4. Mode Generate

### 4.1 Explore Angles

Default. Satu keyword boleh menghasilkan maksimal 5 artikel dengan angle berbeda.

Aturan:

- proses angle secara berurutan;
- setiap angle harus tetap dalam scope niche dan dynamic guide;
- jangan mengulang judul, struktur, atau pembahasan angle sebelumnya;
- duplicate risk harus `low` atau `medium`;
- jika duplicate risk `high`, rewrite angle dan konten.

Angle yang disarankan:

- panduan pemula;
- checklist;
- kesalahan umum;
- perbandingan;
- troubleshooting;
- studi kasus;
- FAQ mendalam.

Jangan membuat artikel harga/biaya kecuali user secara eksplisit mengizinkan.

### 4.2 Refresh

Keyword digunakan untuk memperbarui artikel lama.

Aturan:

- jangan membuat artikel baru jika konten lama sudah ada;
- buat rekomendasi update;
- pertahankan intent utama;
- tambah bagian yang kurang.

### 4.3 Structured Writing Pipeline

Untuk mode Nine Router OpenAI-compatible, satu artikel dibuat secara bertahap:

1. Strategi search intent, angle, title, slug, meta description, category, dan tags.
2. Outline tepat 6 section isi yang unik; FAQ dan kesimpulan tidak masuk ke enam section ini.
3. Batch 1 berisi intro serta section 1-2.
4. Batch 2 berisi section 3-5 dan wajib menyambung tanpa mengulang batch sebelumnya.
5. Tahap final berisi section 6, kesimpulan, 4-5 FAQ, image brief, dan editorial review.
6. WordPress menggabungkan semua batch lalu menjalankan validasi panjang, struktur, SEO, duplikasi, topik terlarang, dan gambar sebelum publish.

Setiap tahap wajib mengikuti keyword, niche, target audience, brand tone, dynamic guide, dan duplicate context yang sama. Output suatu tahap tidak boleh menulis ulang bagian yang menjadi tanggung jawab tahap lain.

## 5. Struktur Artikel

Artikel final harus memiliki struktur:

1. Title.
2. Meta description.
3. Intro 2-3 paragraf.
4. Tepat 6 bagian isi H2 yang mengikuti outline.
5. Subheading H3 jika dibutuhkan.
6. Satu bagian Kesimpulan H2.
7. Satu bagian FAQ H2 dengan 4-5 pertanyaan H3.
8. Internal link relevan jika tersedia.
9. Link sosial/backlink/external yang diberikan user jika relevan.

Artikel final harus memiliki minimal 8 H2 dan 1.200-2.200 kata. Jangan memakai H1 di dalam `content_html` karena title WordPress sudah menjadi heading utama halaman.

Konten HTML hanya boleh memakai tag yang aman:

- `<p>`
- `<h2>`
- `<h3>`
- `<ul>`
- `<ol>`
- `<li>`
- `<strong>`
- `<em>`
- `<a>`
- `<blockquote>` jika benar-benar perlu

Jangan memakai inline style, script, iframe, atau markup berbahaya.

## 6. SEO Rules

Artikel harus mengikuti aturan SEO berikut:

- Keyword utama muncul natural di title.
- Keyword utama muncul natural di paragraf pertama atau kedua.
- Keyword utama muncul di minimal satu H2 jika tidak memaksa.
- Keyword utama, sinonim, dan related entities harus digunakan cukup sering secara natural.
- Meta description 120-155 karakter.
- Title ideal 50-70 karakter.
- Slug pendek, lowercase, dan memakai tanda hubung.
- Gunakan sinonim dan related entities.
- Jangan keyword stuffing.
- Gunakan internal link dengan anchor natural.
- Gunakan link sosial media/backlink yang diberikan user secara natural jika konteksnya cocok.
- Gunakan FAQ untuk long-tail query jika relevan.
- Gunakan alt image yang deskriptif dan mengandung konteks keyword.

## 7. Kualitas Narasi

Artikel harus:

- punya alur dari masalah ke solusi;
- tidak pindah topik tanpa transisi;
- tidak memakai klaim kosong seperti "terbaik", "terbukti", atau "nomor satu" tanpa konteks;
- tidak terlalu generik;
- memberi contoh konkret;
- memberi langkah praktis jika intent how-to;
- memberi perbandingan jelas jika intent commercial;
- memberi penyebab dan solusi jika intent troubleshooting.

Hindari:

- pembukaan terlalu panjang;
- kalimat promosi berlebihan;
- clickbait yang tidak dipenuhi isi artikel;
- paragraf yang hanya mengulang ide sama;
- istilah teknis tanpa penjelasan;
- fakta yang berubah cepat tanpa sumber.
- pembahasan harga/biaya jika tidak diminta eksplisit.
- topik SARA dan politik.

## 8. Fact Safety

Jangan mengarang:

- statistik;
- harga;
- peraturan;
- jadwal;
- data medis;
- data legal;
- data finansial;
- kutipan tokoh;
- nama lembaga;
- hasil riset.
- harga atau estimasi biaya.

Jika butuh menyebut hal yang bisa berubah, gunakan bahasa aman:

- "umumnya"
- "sering kali"
- "tergantung kondisi"
- "ikuti panduan resmi"
- "cek informasi terbaru dari sumber resmi"

Untuk topik medis, legal, finansial, atau keselamatan:

- beri disclaimer ringan;
- sarankan pembaca berkonsultasi dengan profesional;
- jangan memberi instruksi berisiko.

Untuk topik SARA dan politik:

- jangan membuat artikel;
- return `status: failed` atau `needs_review`;
- isi warning bahwa topik dilarang oleh policy website.

## 9. Image Rules

Gambar harus dibuat dari konteks artikel, bukan keyword mentah. Writing Router cukup menghasilkan prompt dan arahan gambar. Jika Media Library dan Pexels tidak cukup relevan, WordPress akan memanggil Image Router khusus agar token writing tetap efisien.

Output image wajib berisi:

- `prompt`
- `alt`
- `caption`
- `source_type`
- optional `url`
- `watermark_required`

Prompt gambar harus:

- berbahasa Inggris;
- menggambarkan scene nyata;
- konkret dan mudah divisualkan;
- editorial/professional style;
- relevan dengan keyword dan title;
- tanpa teks overlay;
- tanpa logo brand;
- tanpa elemen random.
- aman dari unsur SARA dan politik.

Contoh buruk:

```text
stress work
```

Contoh baik:

```text
realistic editorial photo of an office worker taking a calm breathing break at a desk with laptop and notebook, natural daylight, professional workplace wellness scene
```

Alt text harus berbahasa Indonesia dan menjelaskan gambar secara natural.

Watermark wajib didukung jika `image.watermark.enabled = true`.

Featured image wajib untuk setiap artikel. WordPress harus mencoba ulang sumber gambar maksimal 3 putaran. Jika Media Library, Pexels, Image Router, dan fallback generator semuanya gagal menghasilkan attachment gambar valid, artikel tidak boleh dipublish dan job harus berstatus retry/failed.

## 10. Internal Link Rules

Gunakan internal link hanya jika relevan.

Aturan:

- maksimal 2 internal link per artikel kecuali diminta lain;
- anchor text harus natural;
- jangan memaksa link yang tidak relevan;
- jangan memakai anchor generik berlebihan seperti "klik di sini";
- jika tidak ada internal link relevan, kosongkan.

## 11. External Link Rules

External link hanya digunakan jika diberikan oleh WordPress atau memang dibutuhkan sebagai referensi.

Aturan:

- jangan mengarang URL;
- jangan memasukkan link promosi sendiri;
- jika link berasal dari admin, gunakan secara natural;
- link sosial media boleh dimasukkan jika relevan dengan CTA atau profil brand;
- backlink/external link dari user boleh disisipkan dengan anchor natural;
- untuk link promosi atau affiliate, WordPress dapat menambahkan `nofollow`.

## 12. Category and Tags

Pilih satu kategori utama yang paling sesuai.

Tags:

- 5-10 tag;
- lowercase;
- relevan dengan topik;
- jangan terlalu panjang;
- jangan mengulang kategori;
- jangan membuat tag random.

## 13. FAQ Rules

FAQ harus:

- menjawab pertanyaan nyata pembaca;
- singkat tetapi berguna;
- tidak mengulang persis isi artikel;
- 4-5 pertanyaan;
- aman untuk FAQ schema.

Setiap jawaban idealnya 40-80 kata dan dapat dipahami tanpa membaca paragraf lain. Pada pipeline publish otomatis, FAQ tidak boleh kosong karena menjadi bagian validasi struktur.

## 14. Quality Review Checklist

Sebelum output final, cek:

- Apakah artikel menjawab keyword?
- Apakah search intent benar?
- Apakah judul tidak misleading?
- Apakah intro jelas?
- Apakah H2 tersusun logis?
- Apakah ada bagian yang ngawur atau keluar topik?
- Apakah klaim sensitif aman?
- Apakah keyword dipakai natural?
- Apakah meta description valid?
- Apakah image prompt relevan?
- Apakah duplicate risk rendah?
- Apakah JSON valid?
- Apakah artikel tetap dalam niche dan dynamic guide?
- Apakah artikel bebas SARA dan politik?
- Apakah artikel tidak menyebut harga?
- Apakah link sosial/backlink dipakai secara natural jika tersedia?

Jika belum lolos, rewrite sebelum mengembalikan output.

## 15. Scoring

Berikan skor:

- `seo_score`: 0-100
- `quality_score`: 0-100
- `duplicate_risk`: `low`, `medium`, atau `high`

Kriteria minimum:

- `seo_score >= 80`
- `quality_score >= 80`
- `duplicate_risk != high`

Jika skor kurang, jangan return `status: ready`. Return `status: needs_review` atau rewrite sampai lolos.

Jika duplicate risk tinggi, rewrite otomatis dengan angle berbeda. Jangan mengirim output duplicate sebagai `ready`.

## 16. Required JSON Output

Nine Router wajib mengembalikan JSON valid dengan field:

```json
{
  "job_id": "",
  "status": "ready",
  "keyword": "",
  "intent": "",
  "angle_number": 1,
  "max_angles_per_keyword": 5,
  "angle": "",
  "title": "",
  "slug": "",
  "excerpt": "",
  "meta_description": "",
  "content_html": "",
  "faq": [],
  "tags": [],
  "category": "",
  "image": {
    "source_type": "",
    "prompt": "",
    "alt": "",
    "caption": "",
    "url": "",
    "watermark_required": true
  },
  "seo_score": 0,
  "quality_score": 0,
  "duplicate_risk": "low",
  "warnings": []
}
```

Tidak boleh menambahkan teks di luar JSON.

## 17. Failure Behavior

Jika artikel tidak bisa dibuat dengan aman:

- return `status: failed`;
- isi `warnings`;
- jelaskan penyebab singkat;
- jangan mengarang output supaya terlihat lengkap.

Jika hanya butuh review:

- return `status: needs_review`;
- isi bagian yang sudah berhasil;
- isi warning apa yang perlu diperbaiki.

## 18. Dynamic Guide Rules

Dynamic guide adalah aturan khusus hasil analisis input SaaS customer. Dynamic guide lebih spesifik daripada guide umum ini.

Contoh input:

- niche: `jasa pasang bangunan`
- target audience: `pemilik rumah dan orang yang ingin renovasi`
- language: `Indonesia`
- brand tone: `edukatif, meyakinkan, mudah dipahami`

Maka artikel boleh membahas:

- tips memilih jasa pasang;
- proses pemasangan;
- material;
- checklist sebelum renovasi;
- kesalahan umum;
- perawatan hasil pekerjaan;
- pertanyaan customer sebelum memakai jasa.

Artikel tidak boleh melebar ke:

- otomotif;
- kesehatan;
- politik;
- SARA;
- hiburan;
- topik viral yang tidak relevan;
- harga/biaya jika tidak diminta eksplisit.

Setiap artikel harus terasa seperti dibuat untuk website tersebut, bukan artikel generik lintas niche.

## 19. Publishing Assumption

Output default akan langsung dipublish oleh WordPress jika:

- status `ready`;
- quality score lolos;
- SEO score lolos;
- duplicate risk bukan `high`;
- topik tidak dilarang;
- JSON valid.

Karena output langsung publish, router harus lebih ketat dalam review dan rewrite.
