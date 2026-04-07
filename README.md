# He Backs Up — 플러그인 소스

이 폴더가 WordPress에 설치되는 플러그인 본체입니다.
우측 상단의 "Code > Download.ZIP"을 클릭하거나
이 **[링크](https://github.com/he-works/wp-he-backs-up/archive/refs/heads/main.zip)**를 클릭해서 다운로드 받아주세요.
전체 소개 및 설치 방법은 **[웹사이트의 설치가이드](https://plugin.he-works.co/he-backs-up)** 를 참고해주세요.

## 폴더 구조

```
plugin-dev/
├── he-backs-up.php       # 플러그인 메인 파일 (진입점)
├── includes/
│   ├── class-hbu-activator.php       # 활성화/비활성화 처리
│   ├── class-hbu-backup-engine.php   # 백업 실행 엔진
│   ├── class-hbu-restore-engine.php  # 복구 실행 엔진
│   ├── class-hbu-db-dumper.php       # 데이터베이스 덤프
│   ├── class-hbu-file-zipper.php     # 파일 ZIP 압축
│   ├── class-hbu-local-storage.php   # 로컬 저장소 관리
│   ├── class-hbu-gdrive-auth.php     # Google Drive OAuth 인증
│   ├── class-hbu-gdrive-client.php   # Google Drive API 클라이언트
│   ├── class-hbu-backup-registry.php # 백업 목록 레지스트리
│   ├── class-hbu-cron-manager.php    # 자동 스케줄 관리
│   └── class-hbu-logger.php          # 로그 기록
├── admin/
│   ├── class-hbu-admin.php           # 관리자 메뉴 등록
│   └── pages/
│       ├── page-dashboard.php        # 대시보드 (백업 목록, 즉시 백업)
│       ├── page-gdrive.php           # Google Drive 연동 설정
│       └── page-settings.php         # 저장소 및 스케줄 설정
├── assets/
│   ├── css/                          # 관리자 스타일시트
│   └── js/                           # 관리자 스크립트
├── oauth-relay/
│   └── callback.php                  # Google OAuth 콜백 릴레이
└── uninstall.php                     # 플러그인 삭제 시 정리
```
