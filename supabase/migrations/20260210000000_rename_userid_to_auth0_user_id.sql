-- Rename user_id to auth0_user_id and change type from UUID to TEXT.

DROP POLICY IF EXISTS "Users can only insert their own data" ON reviews;
DROP POLICY IF EXISTS "Users can only update their own data" ON reviews;
DROP POLICY IF EXISTS "Allow select rating" ON reviews;

-- Rename and change type
ALTER TABLE reviews RENAME COLUMN user_id TO auth0_user_id;
ALTER TABLE reviews ALTER COLUMN auth0_user_id TYPE text USING auth0_user_id::text;

-- Recreate policies with new column name
CREATE POLICY "Users can only insert their own data"
ON reviews
FOR INSERT
WITH CHECK (auth0_user_id = (auth.jwt() ->> 'sub'));

CREATE POLICY "Users can only update their own data"
ON reviews
FOR UPDATE
USING (auth0_user_id = (auth.jwt() ->> 'sub'));

CREATE POLICY "Allow select rating"
ON reviews
FOR SELECT
USING (true);
