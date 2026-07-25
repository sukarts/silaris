-- Rôle applicatif NON-propriétaire → soumis à la RLS (contrairement au superuser).
-- Les migrations tournent en superuser (postgres) et créent les tables ;
-- l'app se connecte via silaris_login → RLS + FORCE actifs.
CREATE ROLE silaris_login LOGIN PASSWORD 'silaris_prod_pw';
GRANT CONNECT ON DATABASE silaris TO silaris_login;
ALTER DATABASE silaris SET search_path TO public;
-- Droits accordés après migration via un hook post-migrate (voir guide) ; pour la
-- répétition, on accorde largement sur le schéma public à la création des objets :
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO silaris_login;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO silaris_login;
