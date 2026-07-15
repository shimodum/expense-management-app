# Controller設計

本ドキュメントは、`02_screen_list.md`(画面一覧)・`03_function_list.md`(機能一覧)・`04_er_diagram.md`(ER図・DB設計)・`05_table_definition.md`(テーブル定義)・`06_routing_design.md`(ルーティング設計)を前提とし、Controller層の設計をまとめたものである。

本ドキュメントの対象範囲はControllerの設計のみとする。Controller・Service・Policy・FormRequest・Model・Migration・Bladeの実装(コード)はまだ行わず、責務分担・処理フロー・入出力を確定させることを目的とする。Policy・Service・FormRequest・Modelは本ドキュメント上で「どう使われるか」を定義するが、それらのクラス自体の内部実装(具体的な条件式・メソッドの中身)は実装フェーズで行う。

## 設計方針の要点

- Laravel 12を前提とする(`06_routing_design.md`と同様)。
- MVP・プロジェクト方針(`00_project_policy.md`5章「シンプルさを優先し、過剰な設計・抽象化を避ける」)を崩さない。Service層を機能単位で細分化せず、`ExpenseReportService`1クラスに集約する。
- Controllerは「司令塔」とし、責務を持ちすぎない。Controllerが行うのは「リクエストを受け取り、認可(Policy)・検証(FormRequest)を通過させ、業務処理をServiceまたはModelの単純な参照に委譲し、レスポンス(redirect/view)を返す」ことのみとする。
- 認可はPolicy(`ExpenseReportPolicy`)へ、バリデーションはFormRequestへ、状態遷移を伴う業務処理はService(`ExpenseReportService`)へ、DB操作の詳細はModelへ委譲する。
- 認証(ログイン/ログアウト)はLaravel標準の`Auth`ファサードのみで完結させ、Serviceを介さない。フレームワーク標準機能の薄いラップに独自Serviceを挟むことは、本プロジェクトが避けたい「過剰な設計」に該当すると判断したためである。
- 一覧・フォーム表示など読み取りのみのActionは、Serviceを経由せずModelから直接取得する。Serviceは「状態を変更する業務処理」に限定して使う。
- FormRequestを引数に型宣言したActionでは、Controllerメソッドが呼ばれる前にLaravelが自動的にFormRequestの`authorize()`→バリデーションの順で実行する。この仕組みに合わせ、既存レコードに対する操作(`update`/`reject`、Admin一覧の絞り込み含む)は認可判定を`FormRequest::authorize()`から`ExpenseReportPolicy`へ委譲し、Controller側で重ねて`Gate::authorize()`を呼び出さない。認可条件の判定ロジック自体は常にPolicyにのみ書き、FormRequestには重複して条件式を書かない。
- 新規作成(`store`)は対象レコードが存在せずオーナー確認の対象がないため、`StoreExpenseReportRequest::authorize()`は`role:employee`ミドルウェア通過済みであることを前提に`true`を固定で返す。Admin一覧(`index`)の`AdminExpenseReportIndexRequest::authorize()`も同様に、`role:admin`ミドルウェア通過済みであることを前提に`true`を固定で返す。
- FormRequestを利用しないAction(`show`/`edit`/`destroy`/`submit`/`resubmit`/`approve`)は、従来通りControllerから`Gate::authorize()`を呼び出す。

## 1. Controller一覧

| Controller | 配置 | 対象利用者 | 役割 |
|---|---|---|---|
| `AuthController` | `app/Http/Controllers` | 一般社員/管理者(共用) | ログイン/ログアウト(F-01, F-02) |
| `HomeController` | `app/Http/Controllers` | 一般社員/管理者(共用) | ログイン後の初期リダイレクト振り分け(F-17) |
| `ExpenseReportController` | `app/Http/Controllers` | 一般社員 | 経費申請のCRUD・提出・再提出(F-05〜F-11) |
| `Admin\ExpenseReportController` | `app/Http/Controllers/Admin` | 管理者 | 経費申請の一覧・詳細・承認・却下(F-12〜F-15) |

## 2. Controllerごとの責務

### AuthController

ログイン・ログアウトのみを扱う。`Auth::attempt()` / `Auth::logout()` というLaravel標準機能を呼び出すだけの薄いControllerとし、Service・Policy・Modelへの委譲は行わない(認証はアプリ固有の業務ルールではなくフレームワーク標準機能であるため)。

### HomeController

ログイン直後の`/`アクセスを、ログイン中ユーザーのロールに応じて一般社員向け一覧(`expense-reports.index`)または管理者向け一覧(`admin.expense-reports.index`)へリダイレクトするだけの単一Action Controller。業務処理を持たない。

### ExpenseReportController

一般社員が自分の経費申請に対して行うCRUD操作・状態遷移操作(提出・再提出)を扱う。すべてのAction(一覧・作成フォーム表示を除く)で、対象の`ExpenseReport`に対し`ExpenseReportPolicy`による認可を行った上で、入力を伴うActionは`FormRequest`で検証し、状態を変更するActionは`ExpenseReportService`に処理を委譲する。

### Admin\ExpenseReportController

管理者が全社員の提出済み・承認済み・却下の経費申請を確認し、承認・却下を行う。一般社員向けControllerと同じ`ExpenseReportPolicy`・`ExpenseReportService`を利用し、認可条件・業務処理を重複して実装しない。一覧表示(`index`)では`AdminExpenseReportIndexRequest`によりstatusクエリパラメータを検証する。

## 3. Action一覧

`06_routing_design.md`のルート表と1:1対応する。

| 機能ID | Controller@Action | HTTPメソッド | URI |
|---|---|---|---|
| F-01 | `AuthController@showLoginForm` | GET | `/login` |
| F-01 | `AuthController@login` | POST | `/login` |
| F-02 | `AuthController@logout` | POST | `/logout` |
| F-17 | `HomeController@redirect` | GET | `/` |
| F-05 | `ExpenseReportController@index` | GET | `/expense-reports` |
| F-07 | `ExpenseReportController@create` | GET | `/expense-reports/create` |
| F-07 | `ExpenseReportController@store` | POST | `/expense-reports` |
| F-06 | `ExpenseReportController@show` | GET | `/expense-reports/{expense_report}` |
| F-08 | `ExpenseReportController@edit` | GET | `/expense-reports/{expense_report}/edit` |
| F-08 | `ExpenseReportController@update` | PUT | `/expense-reports/{expense_report}` |
| F-09 | `ExpenseReportController@destroy` | DELETE | `/expense-reports/{expense_report}` |
| F-10 | `ExpenseReportController@submit` | POST | `/expense-reports/{expense_report}/submit` |
| F-11 | `ExpenseReportController@resubmit` | POST | `/expense-reports/{expense_report}/resubmit` |
| F-12 | `Admin\ExpenseReportController@index` | GET | `/admin/expense-reports` |
| F-13 | `Admin\ExpenseReportController@show` | GET | `/admin/expense-reports/{expense_report}` |
| F-14 | `Admin\ExpenseReportController@approve` | POST | `/admin/expense-reports/{expense_report}/approve` |
| F-15 | `Admin\ExpenseReportController@reject` | POST | `/admin/expense-reports/{expense_report}/reject` |

## 4. Actionごとの役割

| Controller@Action | 役割 |
|---|---|
| `AuthController@showLoginForm` | ログインフォーム(SC-01)を表示する |
| `AuthController@login` | 認証情報を検証しセッションを開始する |
| `AuthController@logout` | セッションを終了する |
| `HomeController@redirect` | ロールに応じて初期画面へリダイレクトする |
| `ExpenseReportController@index` | 自分の経費申請一覧(SC-02)を表示する |
| `ExpenseReportController@create` | 新規登録フォーム(SC-04)を表示する |
| `ExpenseReportController@store` | 新規申請を下書きとして登録する |
| `ExpenseReportController@show` | 申請詳細(SC-03)を表示する |
| `ExpenseReportController@edit` | 編集フォーム(SC-04)を表示する |
| `ExpenseReportController@update` | 申請内容を更新する(ステータス変更なし) |
| `ExpenseReportController@destroy` | 下書き状態の申請を削除する |
| `ExpenseReportController@submit` | 下書きを提出済みにする |
| `ExpenseReportController@resubmit` | 却下を提出済みに戻す |
| `Admin\ExpenseReportController@index` | 全社員の申請一覧(SC-05)をステータス絞り込み付きで表示する |
| `Admin\ExpenseReportController@show` | 申請詳細(SC-06)を表示する |
| `Admin\ExpenseReportController@approve` | 提出済みの申請を承認する |
| `Admin\ExpenseReportController@reject` | 提出済みの申請を却下する(理由コメント任意) |

## 5. Actionごとの処理フロー

### AuthController

#### showLoginForm

- 入力: なし
- 処理概要: ログインフォームのビューを返す
- 利用するPolicy: なし
- 利用するFormRequest: なし
- 呼び出すService: なし
- 利用するModel: なし
- 戻り値: `view('auth.login')`

#### login

- 入力: `LoginRequest`(email, password)
- 処理概要:
  1. `LoginRequest`でバリデーション(email/passwordの形式)
  2. `Auth::attempt()`で認証を試行
  3. 成功時: セッション再生成 → `home`ルート(`HomeController@redirect`)へredirect
  4. 失敗時: `back()->withInput()->withErrors(...)`でSC-01に留まる
- 利用するPolicy: なし
- 利用するFormRequest: `LoginRequest`
- 呼び出すService: なし(`Auth`ファサードを直接利用)
- 利用するModel: `User`(`Auth`ファサード内部で参照)
- 戻り値: `redirect()->route('home')` または `back()`

#### logout

- 入力: `Illuminate\Http\Request`
- 処理概要:
  1. `Auth::logout()`で認証状態を解除
  2. `$request->session()->invalidate()`でセッションを無効化
  3. `$request->session()->regenerateToken()`でCSRFトークンを再生成
  4. `redirect()->route('login')`
- 利用するPolicy: なし
- 利用するFormRequest: なし
- 呼び出すService: なし
- 利用するModel: なし
- 戻り値: `redirect()->route('login')`

### HomeController

#### redirect

- 入力: なし(ログイン中ユーザー情報のみ参照)
- 処理概要: `Auth::user()->role`を判定し、`employee`なら`expense-reports.index`、`admin`なら`admin.expense-reports.index`へリダイレクトする
- 利用するPolicy: なし
- 利用するFormRequest: なし
- 呼び出すService: なし
- 利用するModel: `User`(ログイン中ユーザーの参照のみ)
- 戻り値: `redirect()->route(...)`

### ExpenseReportController(一般社員)

#### index (F-05)

- 入力: なし(ログイン中ユーザーのみ)
- 処理概要: ログイン中ユーザーが登録した申請を全ステータス分取得し、一覧ビューに渡す
- 利用するPolicy: なし(取得クエリの時点で`user_id`を絞り込むため、個別レコード単位の認可は不要)
- 利用するFormRequest: なし
- 呼び出すService: なし(Modelの直接参照で完結)
- 利用するModel: `ExpenseReport`(例: `ExpenseReport::where('user_id', $userId)->latest()->get()`)
- 戻り値: `view('expense_reports.index', [...])`

#### create (F-07表示)

- 入力: なし
- 処理概要: 経費カテゴリマスタ(F-16)を取得し、新規登録フォームのビューに渡す
- 利用するPolicy: なし(ログイン済み一般社員であれば誰でも新規作成可能)
- 利用するFormRequest: なし
- 呼び出すService: なし
- 利用するModel: `ExpenseCategory`(`ExpenseCategory::all()`)
- 戻り値: `view('expense_reports.create', [...])`

#### store (F-07)

- 入力: `StoreExpenseReportRequest`(expense_date, amount, expense_category_id, payee, description, receipt_image[nullable])
- 処理概要(Controllerメソッドが呼ばれる前に①②がFormRequestにより自動実行される):
  1. `StoreExpenseReportRequest::authorize()` → `true`固定(新規作成のためオーナー確認の対象がなく、`role:employee`ミドルウェア通過済みを前提とする。Policyへの委譲なし)
  2. `StoreExpenseReportRequest`でバリデーション(NG時は`back()->withInput()`)
  3. `ExpenseReportService::create($user, $validated)`を呼び出し(下書きとして新規作成、領収書画像の保存を含む)
  4. `redirect()->route('expense-reports.show', $expenseReport)` + 成功メッセージをflash
- 利用するPolicy: なし(`StoreExpenseReportRequest::authorize()`は`true`固定。新規作成のため対象レコードが存在せず、オーナー確認の対象がない)
- 利用するFormRequest: `StoreExpenseReportRequest`
- 呼び出すService: `ExpenseReportService@create`
- 利用するModel: `ExpenseReport`(Service経由で作成)
- 戻り値: `redirect()->route('expense-reports.show', $expenseReport)`

#### show (F-06)

- 入力: 経路パラメータ`{expense_report}`(ルートモデルバインディング)
- 処理概要:
  1. `Gate::authorize('view', $expenseReport)`でオーナー確認(F-04)
  2. 申請の全項目を取得し、ステータスに応じた操作導線とともにビューへ渡す
- 利用するPolicy: `ExpenseReportPolicy@view`
- 利用するFormRequest: なし
- 呼び出すService: なし
- 利用するModel: `ExpenseReport`(ルートモデルバインディングで取得済み)
- 戻り値: `view('expense_reports.show', [...])`

#### edit (F-08表示)

- 入力: 経路パラメータ`{expense_report}`
- 処理概要:
  1. `Gate::authorize('update', $expenseReport)`でオーナー一致・ステータス(下書き/却下)を確認
  2. 経費カテゴリマスタを取得し、編集フォーム(SC-04、createと共用)のビューへ渡す
- 利用するPolicy: `ExpenseReportPolicy@update`
- 利用するFormRequest: なし
- 呼び出すService: なし
- 利用するModel: `ExpenseReport`, `ExpenseCategory`
- 戻り値: `view('expense_reports.edit', [...])`(または`create`と共通のBladeビュー)

#### update (F-08)

- 入力: `UpdateExpenseReportRequest` + 経路パラメータ`{expense_report}`
- 処理概要(Controllerメソッドが呼ばれる前に①②がFormRequestにより自動実行される):
  1. `UpdateExpenseReportRequest::authorize()` → `ExpenseReportPolicy@update`へ委譲(オーナー一致・ステータスが下書き/却下かを判定。NG時は403、Controller未到達)
  2. `UpdateExpenseReportRequest`でバリデーション(NG時は`back()->withInput()`、Controller未到達)
  3. `ExpenseReportService::update($expenseReport, $validated)`を呼び出し(内容更新、領収書画像の差し替え・削除を含む。ステータスは変更しない)
  4. `redirect()->route('expense-reports.show', $expenseReport)` + 成功メッセージをflash
- 利用するPolicy: `ExpenseReportPolicy@update`(`UpdateExpenseReportRequest::authorize()`から呼び出される。Controllerからは呼び出さない)
- 利用するFormRequest: `UpdateExpenseReportRequest`
- 呼び出すService: `ExpenseReportService@update`
- 利用するModel: `ExpenseReport`
- 戻り値: `redirect()->route('expense-reports.show', $expenseReport)`

#### destroy (F-09)

- 入力: 経路パラメータ`{expense_report}`
- 処理概要:
  1. `Gate::authorize('delete', $expenseReport)`でオーナー一致・下書き状態を確認
  2. `ExpenseReportService::delete($expenseReport)`を呼び出し(添付領収書画像の削除を含む)
  3. `redirect()->route('expense-reports.index')` + 成功メッセージをflash
- 利用するPolicy: `ExpenseReportPolicy@delete`
- 利用するFormRequest: なし(確認モーダルからの操作のみで追加入力なし)
- 呼び出すService: `ExpenseReportService@delete`
- 利用するModel: `ExpenseReport`
- 戻り値: `redirect()->route('expense-reports.index')`

#### submit (F-10)

- 入力: 経路パラメータ`{expense_report}`
- 処理概要:
  1. `Gate::authorize('submit', $expenseReport)`でオーナー一致・下書き状態を確認
  2. `ExpenseReportService::submit($expenseReport, $actor)`を呼び出し(ステータスを`draft`→`submitted`に変更し、`approval_histories`に1行追記。DBトランザクション内で実行)
  3. `redirect()->route('expense-reports.index')` + 成功メッセージをflash
- 利用するPolicy: `ExpenseReportPolicy@submit`
- 利用するFormRequest: なし
- 呼び出すService: `ExpenseReportService@submit`
- 利用するModel: `ExpenseReport`, `ApprovalHistory`
- 戻り値: `redirect()->route('expense-reports.index')`

#### resubmit (F-11)

- 入力: 経路パラメータ`{expense_report}`
- 処理概要:
  1. `Gate::authorize('resubmit', $expenseReport)`でオーナー一致・却下状態を確認
  2. `ExpenseReportService::resubmit($expenseReport, $actor)`を呼び出し(ステータスを`rejected`→`submitted`に変更し、`rejection_reason`をnullにクリアし、`approval_histories`に1行追記。DBトランザクション内で実行)
  3. `redirect()->route('expense-reports.index')` + 成功メッセージをflash
- 利用するPolicy: `ExpenseReportPolicy@resubmit`
- 利用するFormRequest: なし
- 呼び出すService: `ExpenseReportService@resubmit`
- 利用するModel: `ExpenseReport`, `ApprovalHistory`
- 戻り値: `redirect()->route('expense-reports.index')`

### Admin\ExpenseReportController(管理者)

#### index (F-12)

- 入力: `AdminExpenseReportIndexRequest`(status: 任意。許容値`submitted`/`approved`/`rejected`、未指定時のデフォルトは`submitted`)
- 処理概要(Controllerメソッドが呼ばれる前に①②がFormRequestにより自動実行される):
  1. `AdminExpenseReportIndexRequest::authorize()` → `true`固定(`role:admin`ミドルウェア通過済みを前提とする)
  2. `AdminExpenseReportIndexRequest`でバリデーション。許容値は`submitted`/`approved`/`rejected`の3つのみとし、`draft`および許容値外の文字列はすべて検証エラーとする(Controller内にバリデーションルールを直接記述しない)
  3. Controllerは検証済みの`status`(未指定時は`submitted`)で、全社員の申請のうち下書きを除くものを絞り込んで取得(Serviceは経由しない)
  4. `view('admin.expense_reports.index', [...])`
- 利用するPolicy: なし(個別レコード単位の認可は不要。一覧はステータス絞り込みクエリ自体が下書き除外を行う)
- 利用するFormRequest: `AdminExpenseReportIndexRequest`
- 呼び出すService: なし(Modelの直接参照で完結)
- 利用するModel: `ExpenseReport`(例: `ExpenseReport::excludingDraft()->status($validatedStatus)->latest()->get()`)
- 戻り値: `view('admin.expense_reports.index', [...])`

#### show (F-13)

- 入力: 経路パラメータ`{expense_report}`
- 処理概要:
  1. `Gate::authorize('view', $expenseReport)`で下書き以外であることを確認(`view`は一般社員・管理者共通のアクション名で、内部判定がロールに応じて分岐する)
  2. 申請者情報を含む全項目を取得し、ステータスに応じた操作導線(承認/却下)とともにビューへ渡す
- 利用するPolicy: `ExpenseReportPolicy@view`
- 利用するFormRequest: なし
- 呼び出すService: なし
- 利用するModel: `ExpenseReport`(申請者の`User`をEager Load)
- 戻り値: `view('admin.expense_reports.show', [...])`

#### approve (F-14)

- 入力: 経路パラメータ`{expense_report}`
- 処理概要:
  1. `Gate::authorize('approve', $expenseReport)`で提出済み状態であることを確認
  2. `ExpenseReportService::approve($expenseReport, $actor)`を呼び出し(ステータスを`submitted`→`approved`に変更し、`approval_histories`に1行追記。DBトランザクション内で実行)
  3. `redirect()->route('admin.expense-reports.index')` + 成功メッセージをflash
- 利用するPolicy: `ExpenseReportPolicy@approve`
- 利用するFormRequest: なし
- 呼び出すService: `ExpenseReportService@approve`
- 利用するModel: `ExpenseReport`, `ApprovalHistory`
- 戻り値: `redirect()->route('admin.expense-reports.index')`

#### reject (F-15)

- 入力: `RejectExpenseReportRequest`(comment, 任意) + 経路パラメータ`{expense_report}`
- 処理概要(Controllerメソッドが呼ばれる前に①②がFormRequestにより自動実行される):
  1. `RejectExpenseReportRequest::authorize()` → `ExpenseReportPolicy@reject`へ委譲(対象申請が提出済み状態かを判定。NG時は403、Controller未到達)
  2. `RejectExpenseReportRequest`でバリデーション(commentの形式・文字数上限等)
  3. `ExpenseReportService::reject($expenseReport, $actor, $comment)`を呼び出し(ステータスを`submitted`→`rejected`に変更し、`rejection_reason`にcommentを設定し、`approval_histories`に1行追記。DBトランザクション内で実行)
  4. `redirect()->route('admin.expense-reports.index')` + 成功メッセージをflash
- 利用するPolicy: `ExpenseReportPolicy@reject`(`RejectExpenseReportRequest::authorize()`から呼び出される。Controllerからは呼び出さない)
- 利用するFormRequest: `RejectExpenseReportRequest`
- 呼び出すService: `ExpenseReportService@reject`
- 利用するModel: `ExpenseReport`, `ApprovalHistory`
- 戻り値: `redirect()->route('admin.expense-reports.index')`

## 6. Controller・Policy・Service・FormRequest・Modelの責務分担

| 層 | 責務 | やらないこと |
|---|---|---|
| Controller | リクエストの受け付け、(FormRequestを使わないActionのみ)Policyの起動、Service呼び出し(または単純なModel参照)、redirect/viewの返却 | バリデーションルールの記述、認可条件の判定ロジックそのものの記述、状態遷移ロジック、DBトランザクション制御、ファイル保存処理 |
| Policy(`ExpenseReportPolicy`) | 認可条件(オーナー一致、ステータス条件)の判定ロジックを一元的に持つ。Controllerから直接呼ばれる場合と、FormRequestの`authorize()`から呼ばれる場合の両方に対応する | 入力値の形式検証、DBの更新処理 |
| FormRequest | 入力値の形式検証(型・必須・範囲・マスタ存在チェック等)。既存レコードを操作するAction(`update`/`reject`、Admin一覧の`index`)では、`authorize()`から`ExpenseReportPolicy`の判定結果をそのまま返す(条件式自体はFormRequestに書かない)。新規作成(`store`)・Admin一覧は対象レコードがない、またはロールミドルウェアで十分なため`authorize()`は`true`固定 | 認可条件の判定ロジックそのものの記述(常にPolicyへ委譲)、DBの更新処理、状態遷移ロジック |
| Service(`ExpenseReportService`) | 状態を変更する業務処理、`approval_histories`への履歴追記、DBトランザクション制御、領収書画像の保存・削除 | 認可判定、入力値の形式検証、HTTPリクエスト/レスポンスの直接操作 |
| Model(`ExpenseReport`, `ExpenseCategory`, `User`, `ApprovalHistory`) | Eloquentのリレーション定義、絞り込み用スコープ、属性キャスト(PHP Enum等) | 業務ルールの判定、複数テーブルにまたがるトランザクション制御 |

- 読み取りのみのAction(index/create/edit表示)は、上記のうちController・Model(・Policy)のみで完結し、Serviceは関与しない。ただしAdmin一覧(`index`)は`status`検証のため`AdminExpenseReportIndexRequest`を利用する。
- 状態を変更するAction(store/update/destroy/submit/resubmit/approve/reject)は、Serviceが実際のDB更新とトランザクション制御を担う。認可の呼び出し経路はActionにより異なる。
  - `update`/`reject`: Controllerメソッドが呼ばれる前にFormRequestの`authorize()`が`ExpenseReportPolicy`へ委譲する形で実行される(Controller→Serviceの間にControllerからの明示的なPolicy呼び出しはない)。
  - `destroy`/`submit`/`resubmit`/`approve`: FormRequestを使わないため、Controllerが`Gate::authorize()`を呼び出してからServiceを呼び出す。
  - `store`: FormRequestの`authorize()`は`true`固定(Policyは利用しない)。

## 7. リダイレクト方針

| ケース | リダイレクト先 |
|---|---|
| 新規登録(store)・更新(update)成功後 | 対象申請の詳細画面(`expense-reports.show`) |
| 削除(destroy)・提出(submit)・再提出(resubmit)成功後 | 一般社員向け一覧画面(`expense-reports.index`) |
| 承認(approve)・却下(reject)成功後 | 管理者向け一覧画面(`admin.expense-reports.index`) |
| FormRequestのバリデーション失敗 | `back()->withInput()`(Laravel標準の`FormRequest`の挙動に従う。Controllerで個別に実装しない) |
| Policyの認可失敗(`Gate::authorize`) | `AuthorizationException`により自動的に403レスポンス。Controllerで個別にcatchしない |
| ログイン失敗 | `back()->withInput()->withErrors(...)`でSC-01に留まる |
| 未認証アクセス(`auth`ミドルウェア) | `/login`へリダイレクト(ミドルウェア標準機能。Controllerでは扱わない) |

## 8. Flash Message方針

- セッションキーは`success`の1種類に統一する。バリデーションエラーは`$errors`変数(Laravelの`ValidationException`標準機能)、認可エラーは403エラーページに任せるため、`error`系のflashキーは基本的に用いない。
- Action完了時のメッセージ例:

| Action | メッセージ例 |
|---|---|
| store | 経費申請を登録しました |
| update | 経費申請を更新しました |
| destroy | 経費申請を削除しました |
| submit | 経費申請を提出しました |
| resubmit | 経費申請を再提出しました |
| approve | 経費申請を承認しました |
| reject | 経費申請を却下しました |

- 表示側(Bladeの共通レイアウトでの`session('success')`表示方法)は、View実装フェーズで決定する。

## 9. Controllerで扱わない責務

- バリデーションルールの記述(→FormRequest)
- 認可条件(オーナー一致・ステータス判定)の判定ロジック(→Policy)
- ステータス遷移・DBトランザクション制御・`approval_histories`への履歴記録(→Service)
- 領収書画像ファイルの保存・削除処理の詳細(→Service)
- 複雑な絞り込み条件を伴うDBクエリの組み立て(→Modelのスコープ)
- HTML・デザイン・画面レイアウト(→Blade)

## 10. 懸念点・確認事項

- `Admin\ExpenseReportController@index`の`status`クエリパラメータの許容値(`submitted`/`approved`/`rejected`)・デフォルト値(`submitted`)・不正値の扱い(`draft`および許容値外は`AdminExpenseReportIndexRequest`でバリデーションエラーとする)は本ドキュメントで確定した。ただし、GETリクエストであるため専用の入力フォームがなく、バリデーション失敗時の`$errors`の見せ方(同一URLへの`back()`となる挙動をどう画面表示するか)は、View実装フェーズで確認する。
- Service層を`ExpenseReportService`1クラスに集約する方針としたが、実装を進める中でメソッド数・複雑度が増した場合(例: 履歴記録処理の独立クラス化)は、実装後に分割の要否を再検討する。
- Flash Message(`success`)の共通Blade実装方法(レイアウト側での表示位置・スタイル)は、View実装フェーズで決定する。
- 403(認可失敗)・404(存在しないIDへのアクセス)時のエラーページについて、Laravel標準のエラービューをそのまま使うか、アプリ用にカスタマイズするかは、View実装フェーズで決定する。

## 初心者向け解説

### なぜこのController構成にしたのか

`06_routing_design.md`で決定したルート一覧(Controller@Actionの対応)をそのまま踏襲し、ルートとControllerのActionが常に1:1で対応する構成にした。ルーティング設計と食い違う独自の分割・統合を行うと、「どのルートがどのControllerメソッドを呼ぶのか」を都度確認する必要が生まれ、初心者にとって追いにくくなる。ルーティング設計書を見ればControllerの構成がそのまま推測できる状態を優先した。

### なぜServiceへ処理を委譲するのか

提出・再提出・承認・却下は、単なるステータスカラムの書き換えではなく、「ステータス変更」「却下理由(`rejection_reason`)の更新・クリア」「`approval_histories`への履歴追記」という複数の処理を、1つのDBトランザクションとして必ずまとめて実行する必要がある(`04_er_diagram.md`5章)。この一連の処理をControllerに直接書くと、Controllerが「HTTPの窓口」という役割を超えて業務ルールそのものを抱え込み、肥大化し、テストもしにくくなる。業務処理をServiceに切り出すことで、Controllerは「Serviceを呼ぶだけ」の薄い状態を保てる。

一方で、ログイン/ログアウトや一覧・フォーム表示のような、状態変更を伴わない処理・フレームワーク標準機能で完結する処理にまでServiceを用意すると、単なる「素通りするだけのクラス」が増えてしまい、これは`00_project_policy.md`が戒める「過剰な設計・抽象化」に当たる。そのため本設計では、状態変更を伴う業務処理のみをServiceに委譲する方針とした。

### なぜPolicyを使うのか

「自分が登録した申請にのみアクセスできる」(F-04)、「下書き・却下状態のみ編集できる」といった認可条件は、一般社員向け・管理者向けの複数のAction(show/edit/update/destroy/submit/resubmit/approve/reject)で繰り返し必要になる。これをControllerやFormRequestごとに`if`文で書くと、同じ条件があちこちに重複し、修正漏れの原因になる。Laravel標準のPolicyクラス(`ExpenseReportPolicy`)に判定ロジックを1箇所にまとめることで、呼び出す側(Controllerの`Gate::authorize()`、またはFormRequestの`authorize()`)は判定結果を受け取るだけで済み、条件そのものはPolicy側だけを見れば把握できる。呼び出し元がControllerかFormRequestかは、Laravelが「FormRequestを引数に型宣言したときだけ、Controllerメソッドの実行前に自動で`authorize()`とバリデーションを実行する」という標準の仕組みに従って決まる。

呼び出し経路を単純化すると、以下のような一方向の流れになる(呼び出し元がControllerの場合もFormRequestの場合も、Policyが`true`/`false`を返すだけという構造は同じ)。

```
Controller (または FormRequest::authorize())
      ↓ authorize(アクション名, $expenseReport)
   Policy(ExpenseReportPolicy)
      ↓ 判定
   true / false
      ↓
Controllerへ戻る(true: 処理続行 / false: 403)
```

### Laravel標準ではどの部分に対応するのか

| 本ドキュメントの用語 | Laravel標準での位置づけ |
|---|---|
| Controller | Laravelのリソースコントローラの規約(index/create/store/show/edit/update/destroy)に、状態遷移用のカスタムAction(submit/resubmit/approve/reject)を追加したもの |
| Policy | Laravel標準の認可機構(`php artisan make:policy`で生成、`Gate::authorize()`または`$this->authorize()`から呼び出す) |
| FormRequest | Laravel標準のバリデーション機構(`php artisan make:request`で生成、Controllerの引数として型宣言するだけで自動的にバリデーションが走る) |
| Service | Laravel標準機能ではなく、本プロジェクトが採用した設計パターン(業務処理をControllerから分離するための単純なPHPクラス)。Laravelがフレームワークとして強制するものではない |
| Model(Eloquent) | Laravel標準のORM。DB操作・リレーション・スコープを担う |

### このドキュメントで初心者が理解すべきポイント

- Controllerは「交通整理役」であり、業務ロジック(状態遷移・履歴記録)そのものを書く場所ではない。
- 「読み取りだけの処理」と「状態を変更する処理」で、Serviceを使うかどうかの判断が分かれる。すべてを同じパターンで書く必要はない。
- 認可条件・バリデーションルールが複数箇所で必要になったときは、Controllerに書き足すのではなく、Policy・FormRequestという「置き場所」に集約する。
- 「設計をシンプルに保つ」ことと「責務を分離する」ことは対立しない。本ドキュメントのService層(1クラスに集約)がその具体例であり、分離しすぎてクラス数が増えることも、分離せずControllerが肥大化することも、どちらも避けるべき失敗パターンである。
