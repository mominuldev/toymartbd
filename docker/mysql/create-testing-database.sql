-- A separate database for the MySQL-backed test group (collation, locking,
-- FOR UPDATE ordering). The default Pest run uses in-memory SQLite.
CREATE DATABASE IF NOT EXISTS toymartbd_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
