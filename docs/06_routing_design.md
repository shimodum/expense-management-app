# ルーティング設計

本ドキュメントは、`02_screen_list.md`(画面一覧)・`03_function_list.md`(機能一覧)をもとに、Laravelのルーティング(`routes/web.php`)の設計をまとめたものである。DBスキーマの詳細は `04_er_diagram.md` / `05_table_definition.md` を参照する。本ドキュメントの段階ではルート定義・Controller・Middleware・Policyの実装は行わず、設計内容のみを記載する。

## 設計方針の要点

- 本ドキュメントはLaravel 12を前提とする(01_requirements.md 13章「最新の安定バージョンを使用」に基づく)。Laravel 11以降、標準スケルトンから`app/Http/Kernel.php`が廃止されミドルウェア登録は`bootstrap/app.php`で行う構成になっている点、およびController基底クラスに`AuthorizesRequests`トレイトが標準では含まれない構成になっている点は、4章・7章の記載に反映している。
- 一般社員向け機能と管理者向け機能は、URIプレフィックス(`/admin`)とController名前空間(`Admin\`)を分けて実装する。画面一覧(02_screen_list.md)でも一般社員向け(SC-02〜SC-04)と管理者向け(SC-05〜SC-06)は別画面として整理されており、ルーティング上もこの区分をそのまま踏襲する。この名前空間分離自体はアクセス制御の手段ではなく、一般社員向け処理と管理者向け処理の責務・コード構成を明確にするための整理である。アクセス制御(認証・ロール判定・申請オーナー認可)は、`auth`ミドルウェア、`role`ミドルウェア、`ExpenseReportPolicy`が担う(4章・5章参照)。
- 申請オーナー認可(F-04、自分の申請以外にアクセスできない制御)はルーティング/ミドルウェアではなくPolicy(`ExpenseReportPolicy`)で行う。ルートモデルバインディングで取得した`ExpenseReport`に対し、Controller内でPolicyによる認可判定を行う。具体的な呼び出し方法は3章・5章の注記の通り`07_controller_design.md`で統一する。
- 経費申請の登録・編集は同一フォーム(SC-04)を共用するが、ルーティングはLaravelの標準的なリソースコントローラの規約(`create`/`store`/`edit`/`update`)に従い、Controller側でフォームのBladeテンプレートを共通化する。
- 提出・再提出・承認・却下(F-10/F-11/F-14/F-15)は、CRUDの範囲を超えた状態遷移操作であるため、リソースルートに含めず個別の`POST`ルートとして定義する。
- 経費カテゴリ一覧取得(F-16)は独立したHTTPルートを持たない。SC-04(登録・編集フォーム)を表示するController(`create`/`edit`アクション)内でカテゴリマスタを取得し、View側に渡す。02_screen_list.mdにも専用画面がなく、03_function_list.mdでも「F-07・F-08の実行時に付随して利用」と整理されているため。
- URIは`expense_reports`テーブル名に対応させつつ、REST慣習に従いケバブケース(`expense-reports`)で表記する。

## 1. 認証・共通ルート

| 機能ID | HTTPメソッド | URI | ルート名 | Controller@Action | ミドルウェア | 関連画面 |
|---|---|---|---|---|---|---|
| F-01 | GET | `/login` | `login` | `AuthController@showLoginForm` | `guest` | SC-01 |
| F-01 | POST | `/login` | `login.attempt` | `AuthController@login` | `guest` | SC-01 |
| F-02 | POST | `/logout` | `logout` | `AuthController@logout` | `auth` | 全画面共通(ヘッダー) |
| F-17 | GET | `/` | `home` | `HomeController@redirect` | `auth` | ログイン後の初期リダイレクト振り分け専用。ロールに応じ`expense-reports.index`または`admin.expense-reports.index`へリダイレクトする |

- 未認証ユーザーが認証必須ルートへアクセスした場合の`/login`への誘導(F-17)は、`auth`ミドルウェアの標準機能でカバーする。個別のルート定義は不要。
- ユーザー登録(サインアップ)機能は要件・機能一覧に存在しないため、`/register`関連ルートは設けない。

## 2. 一般社員向けルート(`role:employee`)

すべて`middleware(['auth', 'role:employee'])`のグループ内に定義する。

| 機能ID | HTTPメソッド | URI | ルート名 | Controller@Action | 関連画面 | 備考 |
|---|---|---|---|---|---|---|
| F-05 | GET | `/expense-reports` | `expense-reports.index` | `ExpenseReportController@index` | SC-02 | 自分の申請一覧(全ステータス) |
| F-07 | GET | `/expense-reports/create` | `expense-reports.create` | `ExpenseReportController@create` | SC-04 | 新規登録フォーム表示。F-16のカテゴリ一覧をあわせて取得 |
| F-07 | POST | `/expense-reports` | `expense-reports.store` | `ExpenseReportController@store` | SC-04→SC-03 | 下書きとして新規作成 |
| F-06 | GET | `/expense-reports/{expense_report}` | `expense-reports.show` | `ExpenseReportController@show` | SC-03 | Policyでオーナー確認(F-04) |
| F-08 | GET | `/expense-reports/{expense_report}/edit` | `expense-reports.edit` | `ExpenseReportController@edit` | SC-04 | 下書き・却下状態のみ許可。Policyで確認 |
| F-08 | PUT | `/expense-reports/{expense_report}` | `expense-reports.update` | `ExpenseReportController@update` | SC-04→SC-03 | ステータスは変更しない |
| F-09 | DELETE | `/expense-reports/{expense_report}` | `expense-reports.destroy` | `ExpenseReportController@destroy` | SC-03(モーダル)→SC-02 | 下書き状態のみ許可 |
| F-10 | POST | `/expense-reports/{expense_report}/submit` | `expense-reports.submit` | `ExpenseReportController@submit` | SC-03(モーダル)→SC-02 | draft→submitted |
| F-11 | POST | `/expense-reports/{expense_report}/resubmit` | `expense-reports.resubmit` | `ExpenseReportController@resubmit` | SC-03(モーダル)→SC-02 | rejected→submitted |

- `{expense_report}`はルートモデルバインディングにより`ExpenseReport`モデルへ解決する。オーナー確認(F-04)は各Controllerアクション内で`ExpenseReportPolicy`の`view`/`update`/`delete`/`submit`/`resubmit`メソッドに委譲し、ルーティング層では行わない。ControllerからのPolicy呼び出し方法(`Gate::authorize()`か、`AuthorizesRequests`トレイトを明示的に付与した上での`$this->authorize()`か)は本ドキュメントでは確定させず、`07_controller_design.md`で統一する。Laravel 12はController基底クラスに`AuthorizesRequests`トレイトを標準で含まないため、追加のトレイト付与が不要な`Gate::authorize()`を第一候補とする。

## 3. 管理者向けルート(`role:admin`)

すべて`prefix('admin')->name('admin.')->middleware(['auth', 'role:admin'])`のグループ内に定義する。Controllerは`Admin\ExpenseReportController`として一般社員向けと明確に分離する。

| 機能ID | HTTPメソッド | URI | ルート名 | Controller@Action | 関連画面 | 備考 |
|---|---|---|---|---|---|---|
| F-12 | GET | `/admin/expense-reports` | `admin.expense-reports.index` | `Admin\ExpenseReportController@index` | SC-05 | クエリパラメータ`status`で絞り込み(初期値`submitted`)。下書きは対象外 |
| F-13 | GET | `/admin/expense-reports/{expense_report}` | `admin.expense-reports.show` | `Admin\ExpenseReportController@show` | SC-06 | 下書きへのアクセスは拒否(Policyで確認) |
| F-14 | POST | `/admin/expense-reports/{expense_report}/approve` | `admin.expense-reports.approve` | `Admin\ExpenseReportController@approve` | SC-06→SC-05 | submitted→approved |
| F-15 | POST | `/admin/expense-reports/{expense_report}/reject` | `admin.expense-reports.reject` | `Admin\ExpenseReportController@reject` | SC-06(モーダル)→SC-05 | submitted→rejected。却下理由コメントは任意 |

- 管理者は「自分が登録した申請」という概念を持たない(F-04の対象外)ため、オーナー確認は行わない。ただし「下書き状態の申請にはアクセスできない」(F-12・F-13)、「承認・却下は`submitted`状態のみ許可」(F-14・F-15)という制約は、一般社員向けと同じ`ExpenseReportPolicy`の`view`/`approve`/`reject`メソッドで判定する(5章参照)。Controller内に同じ認可条件を重複して記述しない。

## 4. ミドルウェア構成

| ミドルウェア | 役割 | 適用範囲 |
|---|---|---|
| `auth` | 未認証アクセス制御(F-17) | ログイン必須の全ルート |
| `guest` | 認証済みユーザーによるログイン画面への再アクセスを防止 | `/login` |
| `role:employee` | 一般社員のみアクセス許可(F-03) | 一般社員向けルートグループ |
| `role:admin` | 管理者のみアクセス許可(F-03) | 管理者向けルートグループ |

- `role`ミドルウェアはLaravel標準には存在しないため、`app/Http/Middleware/EnsureUserHasRole.php`(仮称)としてMVP実装時にカスタムミドルウェアを作成する方針とする。本プロジェクトはLaravel 12を前提とするため、エイリアス登録は`app/Http/Kernel.php`(Laravel 11以降のスケルトンには存在しない)ではなく、`bootstrap/app.php`の`->withMiddleware()`内で行う。本ドキュメントでは設計のみを扱い、実装(ファイル作成・`bootstrap/app.php`へのエイリアス登録)は行わない。

## 5. 認可(Policy)の適用方針

一般社員向け・管理者向けの双方の認可判定を、単一の`ExpenseReportPolicy`に統一する。Controller内に同じ認可条件(オーナー一致・ステータス判定)を重複して記述しない。

| Policy | 対象モデル | メソッド | 判定内容 | 対応する機能 |
|---|---|---|---|---|
| `ExpenseReportPolicy` | `ExpenseReport` | `view` | 一般社員: 申請の`user_id`とログイン中ユーザーが一致すること。管理者: 対象申請のステータスが`draft`以外であること | F-04, F-06, F-12, F-13 |
| `ExpenseReportPolicy` | `ExpenseReport` | `update` | 一般社員: オーナー一致 かつ ステータスが`draft`または`rejected` | F-04, F-08 |
| `ExpenseReportPolicy` | `ExpenseReport` | `delete` | 一般社員: オーナー一致 かつ ステータスが`draft` | F-04, F-09 |
| `ExpenseReportPolicy` | `ExpenseReport` | `submit` | 一般社員: オーナー一致 かつ ステータスが`draft` | F-04, F-10 |
| `ExpenseReportPolicy` | `ExpenseReport` | `resubmit` | 一般社員: オーナー一致 かつ ステータスが`rejected` | F-04, F-11 |
| `ExpenseReportPolicy` | `ExpenseReport` | `approve` | 管理者: 対象申請のステータスが`submitted`であること | F-14 |
| `ExpenseReportPolicy` | `ExpenseReport` | `reject` | 管理者: 対象申請のステータスが`submitted`であること | F-15 |

- `view`メソッドは一般社員・管理者の双方から呼び出されるが、判定条件はログイン中ユーザーの`role`に応じて内部で分岐させ、呼び出し側(Controller)は常に同じ`view`という認可アクション名を使う。ロール別に別メソッド名(例: `viewForAdmin`)を用意しない。
- `approve`/`reject`は管理者専用アクションのため、`role:admin`ミドルウェアを通過していることを前提としつつ、Policy側でも対象ユーザーが`admin`であることを確認する(ミドルウェアとPolicyの二重チェックではなく、Policyメソッド自体が管理者専用として定義される)。
- Policyクラス自体の実装(メソッドの中身)は、`04_er_diagram.md`のステータス遷移設計を根拠とし、Controller設計工程(`07_controller_design.md`)で行う。

## 6. ルート命名規則

- リソース名は複数形・ケバブケース(`expense-reports`)で統一する。
- 管理者向けルートは`admin.`プレフィックスを付与し、一般社員向けルート名(例: `expense-reports.show`)と`admin.expense-reports.show`のように衝突なく区別できるようにする。
- 状態遷移など、CRUD以外のアクションはルート名の末尾に動詞(`submit` / `resubmit` / `approve` / `reject`)を付与する。

## 7. 懸念点・確認事項

- `role`ミドルウェアは自作が前提となる。Laravel標準の`Gate::before`やSpatie等の外部パッケージを使う代替案もあるが、ロールが2種類の固定値のみというMVPの単純さを踏まえ、外部パッケージ導入は過剰と判断し、自作の軽量ミドルウェアを採用する方針とした。この判断に異論があれば実装フェーズ前に再検討する。
- SC-05(管理者一覧)のステータス絞り込みはクエリパラメータ(`?status=submitted`)による実装を想定しているが、`03_function_list.md`では「一覧表示とステータス絞り込みは分離せず、一覧表示機能に統合する」とのみ記載されており、パラメータ名や未指定時のデフォルト値(`submitted`)の扱いはController実装時に確定させる。
- `expense-reports.create`と`expense-reports.edit`は同一Bladeビューを共用する想定だが、共用の実装方法(同一Blade+条件分岐 か 部分ビューの共通化)はView実装フェーズで決定する。
