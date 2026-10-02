# Approid Desk

Laravel 13, Livewire 4, Tailwind CSS와 Laravel Sail로 구성한 개발 환경입니다.

## 요구 사항

- Docker Desktop
- Node.js 24 이상

PHP와 MySQL은 호스트에 별도로 설치하지 않습니다. Docker에서 PHP 8.4와 MySQL 8.4를 실행합니다.

## 처음 실행

```powershell
npm ci
npm run build
docker compose build
docker compose up -d mysql laravel.test
docker compose exec laravel.test php artisan migrate
docker compose up -d
docker compose exec laravel.test php artisan test
```

서비스는 <http://localhost:8000>에서 확인할 수 있습니다. Queue 워커와 Scheduler 워커는 `docker compose up`으로 함께 실행됩니다.

## 자주 쓰는 명령

```powershell
docker compose ps
docker compose logs -f laravel.test
docker compose exec laravel.test php artisan migrate
docker compose exec laravel.test php artisan test
docker compose down
```

MySQL은 호스트의 `3307` 포트에 연결됩니다. 애플리케이션 내부에서는 `mysql:3306`을 사용합니다.

제공된 React 관리자 템플릿은 변환 작업 전까지 `admin-template-source`에 보존합니다. 이 폴더는 애플리케이션 빌드 대상과 Git 추적 대상에서 제외됩니다.
