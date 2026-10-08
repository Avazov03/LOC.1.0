-- Separate database for PHPUnit (phpunit.pgsql.xml). Tests run migrate:fresh here, never on `internship`.
SELECT 'CREATE DATABASE internship_test OWNER internship'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'internship_test')\gexec
