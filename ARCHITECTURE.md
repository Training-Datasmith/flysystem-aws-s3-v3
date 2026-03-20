# Architecture: flysystem-aws-s3-v3

## Purpose

A Flysystem adapter for AWS S3 using the AWS SDK for PHP v3. Implements the full `FilesystemAdapter` interface and maps Flysystem visibility (`public`/`private`) to S3 ACLs and object metadata.

## Directory Structure

```
AwsS3V3Adapter.php              — Primary adapter: all CRUD and metadata operations against S3
PortableVisibilityConverter.php — Maps Flysystem public/private to S3 ACL strings and back
VisibilityConverter.php         — Interface for custom visibility mapping strategies
S3ClientStub.php                — Test double implementing the S3Client interface
AwsS3V3AdapterTest.php          — Adapter contract tests
```

## Key Design Decisions

- **Bucket + prefix** — the adapter is constructed with a bucket name and optional key prefix; all paths are prefixed automatically, enabling virtual "directories" within a bucket.
- **ACL-based visibility** — `PortableVisibilityConverter` translates `Visibility::PUBLIC` → `public-read` and `Visibility::PRIVATE` → `private`; custom converters can be injected.
- **Streaming reads** — `readStream()` returns the S3 result body as a PHP stream resource, avoiding full in-memory loading of large objects.
- **Empty directory emulation** — S3 has no real directories; the adapter creates zero-byte placeholder objects ending in `/` to simulate directory existence for listing.
- **Metadata caching** — the adapter reads object metadata from `HeadObject` responses and caches within a single request to avoid redundant S3 API calls.

## Extension Points

- Implement `VisibilityConverter` to map visibility to custom S3 ACLs or bucket policies.
- Inject a custom `S3ClientInterface` for mocking in tests or wrapping with retry logic.

## Dependency Flow

```
AwsS3V3Adapter
  ├── S3ClientInterface (AWS SDK v3 or stub)
  ├── VisibilityConverter (ACL mapping)
  └── PathPrefixer (key prefix management)
```
