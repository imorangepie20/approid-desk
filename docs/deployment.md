# Approid Desk 홈서버 배포

## 구성

- 운영 호스트: Zorin OS의 `approid` 계정
- 공개 주소: `https://desk.approid.team`
- 진입 경로: 앱별 Cloudflare Tunnel → `http://127.0.0.1:8080`
- 컨테이너: Nginx/PHP-FPM/Queue/Scheduler 통합 앱, MySQL 8.4, ClamAV 1.4, Cloudflare Tunnel
- 영속 데이터: `mysql-data`, `app-storage`, `clamav-data` Docker 볼륨

앱 포트는 호스트 루프백에만 바인딩한다. 공유기 포트 포워딩이나 공인 인터페이스의 8080 포트 공개는 하지 않는다. Laravel은 이 제한을 전제로 전달 프록시 헤더를 신뢰한다.

전용 MySQL은 애플리케이션의 불변 감사 트리거를 일반 마이그레이션 계정으로 만들 수 있도록 `log_bin_trust_function_creators=1`을 사용한다. 이 DB는 복제하지 않고 외부 포트를 공개하지 않으며 APPROID Desk 전용 계정만 사용한다. 복제를 도입하거나 DB를 공유하기 전에는 이 설정과 마이그레이션 권한을 다시 검토한다.

## 최초 설정

배포 디렉터리는 `/home/approid/apps/approid-desk`를 사용한다. 서버에서 `scripts/configure-production.sh`를 실행하면 SMTP 자격증명과 Cloudflare 터널 토큰을 숨김 입력으로 받고, 앱 키와 DB 비밀번호를 생성해 `.env.production`, `.env.database`, `secrets/tunnel.env`를 권한 `600`으로 만든다. 기존 파일은 덮어쓰지 않는다.

`.env.production`의 필수값은 다음과 같다.

- 새 `APP_KEY`: `docker run --rm --entrypoint php approid-desk:production artisan key:generate --show`
- 충분히 긴 무작위 `DB_PASSWORD`
- 실제 홈서버 SMTP의 호스트, 포트, 인증정보와 발신 주소
- `APP_URL=https://desk.approid.team`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`

`.env.database`에는 같은 DB 이름·사용자·비밀번호와 별도 무작위 `MYSQL_ROOT_PASSWORD`를 넣는다. 실제 비밀값은 Git, 배포 로그, 셸 명령 인자에 남기지 않는다.

메일 서버가 호스트에서 수신한다면 컨테이너에서는 `MAIL_HOST=host.docker.internal`을 사용한다. 587/STARTTLS는 `MAIL_SCHEME=null`, 465/암시적 TLS는 `MAIL_SCHEME=smtps`를 사용한다. 운영 `MAIL_MAILER`는 `smtp`로 고정하며 로그 fallback을 사용하지 않는다. 현재 홈서버 Mailcow가 호스트 내부 경로에 자체 서명 인증서를 제공하므로 해당 로컬 연결에만 `MAIL_VERIFY_PEER=false`를 사용한다. 공인 인증서와 내부 DNS를 정비한 뒤에는 반드시 `true`로 되돌린다.

Mailcow 인증에는 리눅스 사용자명이 아니라 전체 사서함 주소와 그 사서함의 비밀번호를 사용한다. 잘못 입력했거나 비밀번호를 회전한 경우 `scripts/update-smtp-credentials.sh`로 `.env.production`의 메일 항목만 원자적으로 교체하고 앱 컨테이너를 다시 생성한다.

## 배포

```bash
./scripts/configure-production.sh
chmod 600 .env.production .env.database
chmod +x scripts/deploy-production.sh
./scripts/deploy-production.sh
```

스크립트는 운영 이미지를 빌드하고 MySQL·ClamAV 준비를 기다린 뒤 마이그레이션을 실행한다. 이어 앱을 시작하고 Queue 재시작, Compose 상태와 루프백 `/up`을 확인한다. 기존 운영 데이터가 생긴 이후의 배포에서는 마이그레이션 전에 4.38의 DB·첨부 백업 절차를 반드시 먼저 수행한다.

최초 배포에서 사용자 테이블이 비어 있을 때만 `docker compose --env-file .env.production -f compose.production.yaml exec app php artisan desk:create-initial-admin`으로 최고 관리자 한 명을 만든다. 이름·메일·비밀번호는 대화형으로 받고 비밀번호는 표시하지 않는다. 한 명이라도 사용자가 생기면 명령은 이후 실행을 거부하며, 일반 사용자는 최고 관리자가 초대 흐름으로 등록한다.

## Cloudflare Tunnel

Cloudflare Zero Trust에서 `desk.approid.team`의 공개 호스트 이름을 만들고 서비스 URL을 다음과 같이 지정한다.

```yaml
ingress:
  - hostname: desk.approid.team
    service: http://127.0.0.1:8080
  - service: http_status:404
```

터널 토큰은 `scripts/configure-production.sh`가 `secrets/tunnel.env`에만 저장한다. 배포 스크립트는 이 파일이 있으면 `tunnel` 프로필을 함께 시작한다. 토큰과 credentials JSON은 저장소나 앱 컨테이너에 넣지 않는다.

토큰을 잘못 입력했거나 회전한 경우 `scripts/update-tunnel-token.sh`를 실행한다. Cloudflare가 제시하는 Docker 명령 전체나 일반 API 토큰이 아니라 복사 버튼으로 명령의 완전한 `eyJ...` 값만 입력한 뒤 터널 컨테이너를 다시 생성한다. 스크립트는 저장 전에 Base64 JSON과 필수 터널 키를 검사한다.

## 확인

```bash
docker compose --env-file .env.production -f compose.production.yaml ps
docker compose --env-file .env.production -f compose.production.yaml logs --tail=100 app
docker compose --env-file .env.production -f compose.production.yaml exec -T app php artisan desk:check-scheduler
docker compose --env-file .env.production -f compose.production.yaml exec -T app php artisan schedule:list
curl --fail http://127.0.0.1:8080/up
curl --fail https://desk.approid.team/up
```

`desk:check-scheduler`는 매분 갱신되는 데이터베이스 캐시 heartbeat가 기본 180초보다 오래되거나 없으면 실패한다. 이 명령은 Scheduler와 별도로 실행되는 외부 감시에서 주기적으로 호출해야 Scheduler 중단을 탐지할 수 있다. 임계값은 `SCHEDULER_HEARTBEAT_MAX_AGE_SECONDS`로 조정한다.

SMTP는 비밀값을 출력하지 않은 채 TCP/TLS 연결과 실제 테스트 메일 한 건을 확인한다. 그 뒤 운영자의 **알림 발송** 화면에서 전달 건이 `발송 완료`인지 검사한다. 재부팅 후 Docker, 앱, MySQL, ClamAV와 `cloudflared`가 자동 시작하는지는 4.37에서 별도로 검증한다.
