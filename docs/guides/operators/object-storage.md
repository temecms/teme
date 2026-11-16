---
title: Media in object storage
audience: operators
proved: unproved
written_for: the media move and bucket check at Teme's own tag
---

# Media in object storage

> Not proved yet: the commands are tested against stand-ins for a bucket, and nobody has yet moved a site to each provider below.

Teme keeps uploaded files on the server by default. It can keep them in S3-compatible object storage instead: Cloudflare R2, DigitalOcean Spaces, Backblaze B2, Amazon S3 or MinIO. This page says when that is worth it, how to set up the buckets, how to move a site's files without taking it down, and what changes for backups.

## When to move

Move when the media outgrows the server's disk, or when you want a CDN to serve it far from the server. A small site on one server gains little: the server's disk is faster, cheaper and backed up with everything else. Moving is a change to the configuration plus a copy, and you can move back the same way.

## Two buckets

Teme needs two buckets, never one bucket with two folders, because a provider makes a whole bucket public at once:

- **the public bucket**, `MEDIA_BUCKET`: image sizes and every file that isn't an image. Readers fetch these, through a CDN or the bucket's public address, `MEDIA_BUCKET_URL`.
- **the originals bucket**, `MEDIA_ORIGINALS_BUCKET`: the photographs as they were uploaded, with their camera data and location. Never make it public. Editors download an original through an address Teme signs for five minutes.

Both use one access key, which needs to read, write and delete objects in both buckets and nothing else. Teme never lists a bucket. Put the key's secret in `MEDIA_BUCKET_SECRET_ACCESS_KEY`, or in a file that `MEDIA_BUCKET_SECRET_ACCESS_KEY_FILE` names (see [Configuring Teme](configure.md#secrets-from-files)). Every variable is in [Configuring Teme](configure.md#media-storage).

The site never fetches media from the reader's browser by script, so the buckets need no CORS rules.

## Each provider

| Provider | `MEDIA_BUCKET_ENDPOINT` | `MEDIA_BUCKET_REGION` | Making the public bucket public |
|---|---|---|---|
| Cloudflare R2 | `https://<account>.r2.cloudflarestorage.com` | `auto` | Connect a custom domain to the public bucket and give it as `MEDIA_BUCKET_URL`. The bucket's `r2.dev` address is rate-limited and not for a live site. |
| DigitalOcean Spaces | `https://<region>.digitaloceanspaces.com` | the Space's region, such as `nyc3` | Spaces serves only objects that are public themselves, so set `MEDIA_BUCKET_ACL=public-read`. Teme never sends it for originals. Give the Space's CDN address as `MEDIA_BUCKET_URL`. |
| Backblaze B2 | the bucket's S3 endpoint, such as `https://s3.us-west-004.backblazeb2.com` | the region in that endpoint, such as `us-west-004` | Make the public bucket public in B2's settings, and keep the originals bucket private. |
| Amazon S3 | leave it empty | the buckets' region | A bucket policy that lets anyone read the public bucket, or CloudFront in front of it with origin access. Leave ACLs off. |
| MinIO | your MinIO's address | `us-east-1` | Set `MEDIA_BUCKET_PATH_STYLE=true`, and give the public bucket an anonymous download policy. |

**Put a CDN in front of the public bucket.** Teme writes every public file with `Cache-Control: public, max-age=31536000, immutable`: a file's address changes whenever its file does, so a CDN can keep each one for a year and never serve a stale one.

## Check the buckets first

Set the variables on every role, leave `MEDIA_STORAGE` as `server` for now, deploy, and run:

```sh
docker compose exec web php artisan teme:media:bucket-check
```

It writes a small file to each bucket, reads it back, fetches the public one through `MEDIA_BUCKET_URL`, makes sure the original can't be fetched without a signature but can with one, and deletes both. Each failure is a sentence that names the step and the provider's error code. It never prints a key. Fix what it says until it passes.

## The order of a move

A move copies the files the site's records name, never a listing of a folder, and checks every original's checksum after copying it. It runs with the site live, and stops and resumes as often as you need.

1. **Copy everything** while the site runs on the server. This takes the longest:

   ```sh
   docker compose exec web php artisan teme:media:move --from=server --to=bucket
   ```

   Ctrl-C finishes the item in hand and stops. Run the same line again to carry on where it stopped. `php artisan teme:media:move --dry-run` counts the items, files and bytes first, and `php artisan teme:media:move --status` lists the last ten runs.

2. **Catch up** with what editors changed meanwhile, as often as you like:

   ```sh
   docker compose exec web php artisan teme:media:move --from=server --to=bucket --catch-up
   ```

3. **Switch.** Stop the roles that write media, catch up once more, then set `MEDIA_STORAGE=bucket` on every role and deploy:

   ```sh
   docker compose stop worker worker-long scheduler
   docker compose exec web php artisan teme:media:move --from=server --to=bucket --catch-up
   ```

   Then set `MEDIA_STORAGE=bucket` where your other variables are, and run `docker compose up --detach`, which starts every role again with the new value. Every role must read the same value: a worker writing images to the server while `web` reads the bucket breaks every new image.

4. **Catch up again**, now that the site reads the bucket. This run is the one that allows deleting the server's copies:

   ```sh
   docker compose exec web php artisan teme:media:move --from=server --to=bucket --catch-up
   ```

5. **Weeks later, delete the server's copies**, once you are sure you won't go back. Ask first whether you may:

   ```sh
   docker compose exec web php artisan teme:media:move --from=server --to=bucket --delete-source --dry-run
   docker compose exec web php artisan teme:media:move --from=server --to=bucket --delete-source
   ```

   It refuses, deleting nothing, unless the site reads the bucket, the latest copy began after the switch and found no failure, the catch-ups since the last full copy leave no gap, and no backup or other move is running. It checks each file in the bucket once more before deleting the server's copy. It leaves the empty folders, which you can remove with:

   ```sh
   docker compose exec web find storage/app/public/media storage/app/private/media-originals -mindepth 1 -type d -empty -delete
   ```

A run ends with exit status 0 when every file is verified, 1 when some failed, 2 when it was refused, and 3 when it was stopped. The status list names each failed file, and each file missing from both places, which isn't a failure: the move can't lose what was never there. To check a bucket in full while the server still has its copies, run `php artisan teme:media:move --from=server --to=bucket --deep`: it reads back every file, copies again any that is wrong, and checks it once more. After the server's copies are deleted there is nothing to copy from or compare the image sizes with, so the same run checks only the originals, against the checksums Teme recorded, and names any that is wrong.

Addresses that editors pasted into content before the move keep working: Teme recognizes media addresses from the server and from the bucket alike.

## Going back

Moving back to the server is the same order with the places swapped, starting with `php artisan teme:media:move --from=bucket --to=server`, and `MEDIA_STORAGE=server` at the switch. Keep the buckets until the server's copies are verified.

## Backups

Teme's backups read only the server's disk. When media is in a bucket, a backup still holds the database, the application key and the list of every original with its checksum, but not the files, and Tools › Backups says so. Protect the buckets at your provider: turn on versioning, or replication to another region.

If you want the originals in your backups, keep them on the server and move only the public files. Set `MEDIA_STORAGE=bucket` and `MEDIA_ORIGINALS_DISK=media-originals`, and give each step above the option that names the public files only, starting with the check:

```sh
docker compose exec web php artisan teme:media:bucket-check --only=public
docker compose exec web php artisan teme:media:move --from=server --to=bucket --only=public
```

The originals then stay on the server, in every backup, and every image size in the bucket was made from one of them. A restore of a backup made while media was in a bucket brings back the database, says which files it expects in the bucket, and leaves them there.
