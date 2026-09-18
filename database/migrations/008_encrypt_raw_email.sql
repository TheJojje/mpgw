-- ---------------------------------------------------------------------
-- Kryptera raw_emails.raw_headers/raw_body at rest (samma monster som
-- mailboxes.secret_ciphertext/secret_nonce - se CredentialCipher).
-- normalized_text lamnas i klartext: den ar en icke-reversibel,
-- redan-normaliserad text som anvands for regex-matchning, LIKE-sokning
-- (EmailsController::index) och farg-highlighting i admin-vyn, inte
-- ravaran sjalv.
--
-- Miljon har bara dev-data (APP_ENV=local) - detta ar darfor en ren
-- framatmigration utan konvertering av befintliga rader. Om denna
-- migration nagonsin kors mot en databas med befintliga raw_emails-rader
-- fran fore krypteringen infordes maste de raderna bakas om separat
-- (dekryptera-med-nyckel finns inte for data som aldrig krypterades).
-- ---------------------------------------------------------------------
ALTER TABLE raw_emails
    MODIFY COLUMN raw_headers MEDIUMBLOB NULL,
    MODIFY COLUMN raw_body LONGBLOB NULL,
    ADD COLUMN raw_nonce VARBINARY(64) NULL AFTER raw_body;
