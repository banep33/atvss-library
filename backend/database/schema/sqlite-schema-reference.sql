CREATE TABLE "migrations"(
  "id" integer primary key autoincrement not null,
  "migration" varchar not null,
  "batch" integer not null
);
CREATE TABLE "users"(
  "id" integer primary key autoincrement not null,
  "ime" varchar not null,
  "prezime" varchar not null,
  "email" varchar not null,
  "password" varchar not null,
  "broj_clanske_karte" varchar,
  "pristupni_kod_klub" varchar,
  "telefon" varchar,
  "adresa" varchar,
  "remember_token" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "role" varchar not null default 'user'
);
CREATE UNIQUE INDEX "users_email_unique" on "users"("email");
CREATE UNIQUE INDEX "users_broj_clanske_karte_unique" on "users"(
  "broj_clanske_karte"
);
CREATE TABLE "password_reset_tokens"(
  "email" varchar not null,
  "token" varchar not null,
  "created_at" datetime,
  primary key("email")
);
CREATE TABLE "sessions"(
  "id" varchar not null,
  "user_id" integer,
  "ip_address" varchar,
  "user_agent" text,
  "payload" text not null,
  "last_activity" integer not null,
  primary key("id")
);
CREATE INDEX "sessions_user_id_index" on "sessions"("user_id");
CREATE INDEX "sessions_last_activity_index" on "sessions"("last_activity");
CREATE TABLE "cache"(
  "key" varchar not null,
  "value" text not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE TABLE "cache_locks"(
  "key" varchar not null,
  "owner" varchar not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE TABLE "jobs"(
  "id" integer primary key autoincrement not null,
  "queue" varchar not null,
  "payload" text not null,
  "attempts" integer not null,
  "reserved_at" integer,
  "available_at" integer not null,
  "created_at" integer not null
);
CREATE INDEX "jobs_queue_index" on "jobs"("queue");
CREATE TABLE "job_batches"(
  "id" varchar not null,
  "name" varchar not null,
  "total_jobs" integer not null,
  "pending_jobs" integer not null,
  "failed_jobs" integer not null,
  "failed_job_ids" text not null,
  "options" text,
  "cancelled_at" integer,
  "created_at" integer not null,
  "finished_at" integer,
  primary key("id")
);
CREATE TABLE "failed_jobs"(
  "id" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "connection" text not null,
  "queue" text not null,
  "payload" text not null,
  "exception" text not null,
  "failed_at" datetime not null default CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX "failed_jobs_uuid_unique" on "failed_jobs"("uuid");
CREATE TABLE "knjige"(
  "id" integer primary key autoincrement not null,
  "in_broj" varchar,
  "signatura" varchar,
  "datum" date,
  "autor" varchar,
  "naziv_knjige" varchar,
  "mesto_izdavanja" varchar,
  "godina_izdavanja" integer,
  "izdavac" varchar,
  "nacin_nabavke" varchar,
  "isbn" varchar,
  "opis" text,
  "slika" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "je_eknjiga" tinyint(1) not null default '0',
  "eknjiga_link" varchar,
  "status" varchar not null default 'dostupna'
);
CREATE TABLE "clanovi"(
  "id" integer primary key autoincrement not null,
  "ime" varchar not null,
  "prezime" varchar not null,
  "status_clana" varchar check("status_clana" in('admin', 'bibliotekar', 'clan')) not null,
  "adresa" varchar,
  "telefon" varchar,
  "jmbg" varchar not null,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "clanovi_prezime_ime_index" on "clanovi"("prezime", "ime");
CREATE TABLE "zanrovi"(
  "id" integer primary key autoincrement not null,
  "naziv" varchar not null,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "zanrovi_naziv_unique" on "zanrovi"("naziv");
CREATE TABLE "knjiga_zanr"(
  "id" integer primary key autoincrement not null,
  "knjiga_id" integer not null,
  "zanr_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("knjiga_id") references "knjige"("id") on delete cascade,
  foreign key("zanr_id") references "zanrovi"("id") on delete cascade
);
CREATE TABLE "komentari"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "knjiga_id" integer not null,
  "tekst" text not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("knjiga_id") references "knjige"("id") on delete cascade
);
CREATE TABLE "ocene"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "knjiga_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  "ocena" integer not null default '5',
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("knjiga_id") references "knjige"("id") on delete cascade
);
CREATE UNIQUE INDEX "ocene_user_id_knjiga_id_unique" on "ocene"(
  "user_id",
  "knjiga_id"
);
CREATE TABLE "predlozi_knjiga"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "naziv_knjige" varchar not null,
  "autor" varchar not null,
  "razlog" text,
  "status" varchar check("status" in('na_razmatranju', 'prihvacen', 'odbijen')) not null default 'na_razmatranju',
  "odgovor_biblioteke" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE TABLE "dogadjaji"(
  "id" integer primary key autoincrement not null,
  "naslov" varchar not null,
  "opis" text not null,
  "datum_dogadjaja" date not null,
  "tip" varchar check("tip" in('prosledjen', 'predstojeci')) not null default 'predstojeci',
  "mediji_kazu" text,
  "slike" text,
  "kreirao_user_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("kreirao_user_id") references "users"("id") on delete cascade
);
CREATE TABLE "notifikacije"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "rezervacija_id" integer,
  "tip" varchar not null,
  "poruka" text not null,
  "procitano" tinyint(1) not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("rezervacija_id") references "rezervacije"("id") on delete cascade
);
CREATE TABLE zaduzivanja(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  clan_id INTEGER,
  user_id INTEGER,
  primerak_id INTEGER NOT NULL,
  datum_izdavanja DATETIME NOT NULL,
  rok_vracanja DATETIME NOT NULL,
  datum_vracanja DATETIME,
  napomena TEXT,
  created_at DATETIME,
  updated_at DATETIME,
  FOREIGN KEY(clan_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(primerak_id) REFERENCES primerci_knjiga(id) ON DELETE CASCADE
);
CREATE INDEX zaduzivanja_clan_id_primerak_id_index ON zaduzivanja(
  clan_id,
  primerak_id
);
CREATE TABLE "primerci_knjiga"(
  "id" integer primary key autoincrement not null,
  "knjiga_id" integer not null,
  "inventarski_broj" integer not null,
  "signatura" varchar,
  "lokacija" varchar,
  "nacin_nabavke" varchar,
  "cena" numeric,
  "datum_unosa" datetime not null default(CURRENT_TIMESTAMP),
  "created_at" datetime,
  "updated_at" datetime,
  "status" varchar not null default('slobodna'),
  foreign key("knjiga_id") references knjige("id") on delete cascade on update no action
);
CREATE UNIQUE INDEX "primerci_knjiga_inventarski_broj_unique" on "primerci_knjiga"(
  "inventarski_broj"
);
CREATE INDEX "primerci_knjiga_knjiga_id_status_index" on "primerci_knjiga"(
  "knjiga_id",
  "status"
);
CREATE INDEX "primerci_knjiga_signatura_index" on "primerci_knjiga"(
  "signatura"
);
CREATE TABLE "arhiva"(
  "id" integer primary key autoincrement not null,
  "rezervacija_id" integer,
  "user_id" integer,
  "knjiga_id" integer,
  "tip" varchar check("tip" in('Zahtev', 'Odobrena', 'Odbijena', 'Zaduzena', 'Vracena')) not null,
  "naziv_knjige" varchar not null,
  "autor" varchar,
  "ime_clana" varchar not null,
  "email_clana" varchar,
  "broj_clanske_karte" varchar,
  "datum_akcije" datetime not null,
  "created_at" datetime,
  "updated_at" datetime,
  "rok_vracanja" date,
  foreign key("rezervacija_id") references "rezervacije"("id") on delete set null,
  foreign key("user_id") references "users"("id") on delete set null,
  foreign key("knjiga_id") references "knjige"("id") on delete set null
);
CREATE TABLE "activity_logs"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "akcija" varchar not null,
  "opis" text,
  "ip_address" varchar,
  "user_agent" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete set null
);
CREATE TABLE "rezervacije"(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  knjiga_id INTEGER NOT NULL,
  primerak_id INTEGER,
  datum_rezervacije DATETIME,
  datum_odobrenja DATETIME,
  rok_preuzimanja DATETIME,
  datum_preuzimanja DATETIME,
  datum_vracanja DATETIME,
  odobrio_admin_id INTEGER,
  status TEXT NOT NULL DEFAULT "na_cekanju" CHECK(status IN("na_cekanju","odobrena","odbijena","preuzeta","otkazana","istekla","vracena")),
  vracena INTEGER NOT NULL DEFAULT 0,
  created_at DATETIME,
  updated_at DATETIME
);

INSERT INTO migrations VALUES(1,'0001_01_01_000000_create_users_table',1);
INSERT INTO migrations VALUES(2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO migrations VALUES(3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO migrations VALUES(4,'2025_10_27_150550_create_books_table',1);
INSERT INTO migrations VALUES(5,'2025_10_27_150609_create_book_copies_table',1);
INSERT INTO migrations VALUES(6,'2025_10_27_150615_create_members_table',1);
INSERT INTO migrations VALUES(7,'2025_10_27_150621_create_loans_table',1);
INSERT INTO migrations VALUES(8,'2025_12_13_151037_zanrovi_table',1);
INSERT INTO migrations VALUES(9,'2025_12_13_151112_knjiga_zanr_table',1);
INSERT INTO migrations VALUES(10,'2025_12_13_151229_add_eknjiga_to_books_table',1);
INSERT INTO migrations VALUES(11,'2025_12_13_151248_rezervacija_table',1);
INSERT INTO migrations VALUES(12,'2025_12_13_151306_komentari_table',1);
INSERT INTO migrations VALUES(13,'2025_12_13_151318_ocene_table',1);
INSERT INTO migrations VALUES(14,'2025_12_13_151333_predlozi_table',1);
INSERT INTO migrations VALUES(15,'2025_12_13_151349_dogadjaji_table',1);
INSERT INTO migrations VALUES(16,'2025_12_13_153152_add_rezervisana_to_primerci_knjiga_status_enum',1);
INSERT INTO migrations VALUES(17,'2025_12_17_132855_update_ocene_table_add_ocena_field',1);
INSERT INTO migrations VALUES(18,'2025_12_20_140000_fix_zaduzivanja_clan_id',1);
INSERT INTO migrations VALUES(19,'2025_12_24_115030_add_notifications_and_update_rezervacije',1);
INSERT INTO migrations VALUES(20,'2025_12_26_101645_add_status_to_knjige_table',1);
INSERT INTO migrations VALUES(21,'2025_12_26_102435_update_status_column_in_rezervacije_table',1);
INSERT INTO migrations VALUES(22,'2025_12_26_104012_add_vracena_to_rezervacije_table',1);
INSERT INTO migrations VALUES(23,'2025_12_28_154902_add_datum_vracanja_to_rezervacije',1);
INSERT INTO migrations VALUES(24,'2025_12_28_200104_add_primerak_id_to_rezervacije_table',1);
INSERT INTO migrations VALUES(25,'2025_12_28_203529_add_user_id_to_zaduzivanja_table',1);
INSERT INTO migrations VALUES(26,'2026_02_05_112753_add_otkazana_istekla_status_to_rezervacije',1);
INSERT INTO migrations VALUES(27,'2026_02_08_180000_make_clan_id_nullable_in_zaduzivanja',1);
INSERT INTO migrations VALUES(28,'2026_03_02_000002_add_unique_to_primerci_inventarski_broj',1);
INSERT INTO migrations VALUES(29,'2026_03_02_114618_fix_knjige_slika_paths_from_books_to_images',1);
INSERT INTO migrations VALUES(30,'2026_03_12_111204_change_inventarski_broj_to_integer_in_primerci_knjiga',1);
INSERT INTO migrations VALUES(31,'2026_03_19_100841_create_arhiva_table',1);
INSERT INTO migrations VALUES(32,'2026_03_19_110008_add_rok_vracanja_to_arhiva_table',1);
INSERT INTO migrations VALUES(33,'2026_03_31_192819_add_pristupni_kod_klub_to_users_table',1);
INSERT INTO migrations VALUES(34,'2026_04_01_171053_add_bibliotekar_to_users_role_enum',1);
INSERT INTO migrations VALUES(35,'2026_04_02_162137_create_activity_logs_table',1);
INSERT INTO migrations VALUES(36,'2026_04_09_120950_add_vracena_status_to_rezervacije',1);
