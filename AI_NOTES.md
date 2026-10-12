# Catatan penggunaan AI

Catatan pengalaman pribadi di bawah dirangkum dari wawancara dan sudah saya konfirmasi. Struktur dokumen mengikuti kebutuhan tiket #11, tetapi belum dicocokkan dengan template dari brief asli. Ringkasan bantuan AI dan hasil pengujian pribadi masih perlu diperiksa sebelum pengumpulan.

## Alat dan cara kerja

Proyek ini menggunakan Claude Code, skill engineering Matt Pocock, rules Laravel, CodeGraph untuk navigasi kode, dan context-mode untuk membatasi keluaran tool yang masuk ke konteks. Pekerjaan dibagi menjadi tiket dengan branch terpisah, lalu ditinjau melalui GitHub Issues dan PR.

Repo memiliki aturan untuk isolasi institusi, verifikasi HMAC dari raw body, nominal rupiah berupa integer, serta riwayat transaksi dan audit yang hanya boleh ditambah. Penambahan dependency dan perubahan migration juga dibatasi. Hooks dan test menegakkan sebagian aturan tersebut. Pest digunakan untuk pengujian, sedangkan Pint untuk format kode. Urutan implementasi dapat dilihat di riwayat Git; dokumen ini tidak memperkirakan durasi pengerjaan.

## Bagian yang dibantu AI

AI membantu menerjemahkan spesifikasi yang disepakati menjadi tiket dan implementasi. Bantuan tersebut mencakup validasi request, isolasi data institusi, pemrosesan webhook, perhitungan status invoice, simulator, dan rekonsiliasi.

AI juga membantu menyusun dan meninjau test, memeriksa kasus batas, serta menulis dokumentasi API, panduan menjalankan aplikasi, dan catatan keputusan. Review membandingkan perubahan dengan spesifikasi dan standar proyek. Ringkasan ini masih perlu saya periksa sebelum pengumpulan; bantuan AI tidak berarti semua sarannya langsung diterima.

## Saran AI yang saya tolak

Saya biasanya menolak saran ketika solusi AI mulai terlalu rumit atau keluar dari scope PRD. Saat sesi grilling, saya mengoreksi arah pembahasan dan memberi masukan agar AI kembali ke kebutuhan proyek.

Alasan saya menolak adalah agar pekerjaan tetap sesuai PRD dan tidak menambah kerumitan yang belum dibutuhkan. Catatan ini menggambarkan cara saya mengarahkan AI; saya belum menyebutkan satu usulan spesifik beserta alternatif implementasinya.

## Pekerjaan yang saya lakukan sendiri

Saya mereview kode hasil AI secara manual, terutama dari sisi keamanan dan alur bisnis. Review ini bertujuan meminimalkan bug. Saya tidak menganggapnya sebagai jaminan bahwa kode sudah 100% benar.

Pekerjaan mandiri yang saya ceritakan adalah review kode, bukan menulis implementasi tanpa AI. Saya belum memberikan contoh temuan review atau hasil verifikasi tertentu untuk dicatat di sini.

## Pengujian dan kontrol

Proyek memiliki test untuk penolakan signature, isolasi institusi, nominal integer, urutan event, status invoice, refund, pengiriman ulang, dan rekonsiliasi. Test menggunakan HTTP fake dan memblokir request eksternal yang tidak disiapkan. Simulator mengirim request HTTP bertanda tangan untuk pengujian manual pada lingkungan pengembangan.

Dokumentasi Scribe menggunakan contoh respons buatan, tanpa memanggil endpoint atau mengambil contoh data dari database. Token dan HMAC secret tidak dicantumkan dalam catatan ini maupun contoh dokumentasi API.

Hasil menjalankan seluruh test suite secara pribadi belum saya sampaikan. Hasil pengujian yang dijalankan agent tidak dicatat sebagai pengujian pribadi saya.

## Yang ingin saya ubah berikutnya

Saya ingin melengkapi rules serta memperkuat hooks dan guardrail yang sudah ada. Fokusnya adalah membatasi perubahan yang tidak aman, sehingga aturan keamanan tidak hanya bergantung pada instruksi atau review manual.
