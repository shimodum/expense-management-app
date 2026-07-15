# テーブル定義

本ドキュメントは、`04_er_diagram.md` で決定したテーブル構成・リレーション・ステータス遷移をもとに、Laravel Migration実装レベルの詳細(カラム型・制約・インデックス・外部キーの削除方針)を定義するものである。テーブルの採否理由・ER図・リレーション概要・ステータス遷移は `04_er_diagram.md` を参照する。

## 許容値(role / status / action / from_status / to_status)の管理方針

`04_er_diagram.md` 7章で保留としていた、ロール・ステータス等の許容値管理方法を以下の通り決定する。

- DBカラムはすべて `string` 型(MySQLのネイティブ`ENUM`型は使用しない)とする。
- 許容値の妥当性チェックはアプリケーション層(PHP 8.1 Backed Enum + FormRequestのバリデーション)で行う。
- **理由**: MySQLの`ENUM`型は値の追加・変更のたびに`ALTER TABLE`が必要でスキーマ変更コストが高く、テスト環境(SQLite等)との型差異も生まれやすい。PHP Enumクラスであればコード変更のみで済み、IDEの型補完やstatic解析の恩恵も受けられるため、シンプルさを優先する開発原則に合致する。

対象カラムと許容値は以下の通り。

| テーブル.カラム | 許容値 |
|---|---|
| `users.role` | `employee`, `admin` |
| `expense_reports.status` | `draft`, `submitted`, `approved`, `rejected` |
| `approval_histories.action` | `submitted`, `approved`, `rejected` |
| `approval_histories.from_status` / `to_status` | `draft`, `submitted`, `approved`, `rejected` |

## FKカラムのインデックスに関する共通方針

Laravelの`foreignId('xxx_id')`単体は`unsignedBigInteger`カラムを定義するだけで、インデックスは付与しない。しかし`->constrained()`でFK制約を付与すると、MySQL(InnoDB)はFK制約の対象カラムに必ずインデックスを要求する仕様のため、明示的なインデックスが存在しない場合は暗黙のインデックスを自動作成する。

- 本設計で使用するFKカラム(`expense_reports.user_id` / `expense_reports.expense_category_id` / `approval_histories.expense_report_id` / `approval_histories.actor_id`)は、いずれも`foreignId()->constrained()`を使うだけでMySQL上は自動的にインデックスが付与される。
- そのため、これらのFKカラムに対して明示的に`->index()`を重ねて呼ぶ必要はない。MySQLはFK制約に対して既存のインデックスを再利用できるため常に二重生成になるとは限らないが、`->constrained()`と`->index()`を素朴に併記した場合は、既にFK制約側で自動生成されたインデックスと重複・冗長なインデックスが生成される可能性がある(呼び出し順序やインデックス名によって挙動が変わるため、意図的に両方を書く積極的な理由がない限り避ける)。
- 以降の各テーブルの「制約・インデックス」列では、FKカラムは`FK → 参照先`とのみ記載し、「INDEX」という表記は使わない(自動付与であり、意図的に追加しているものではないため)。
- 一方、FKではない単純なカラム(例: `expense_reports.status`)は自動インデックスの対象外であり、絞り込み用途で必要な場合は明示的に`->index()`を呼ぶ。
- **注意**: これはMySQL(InnoDB)固有の挙動であり、PostgreSQLやSQLiteではFK制約からのインデックス自動生成は行われない。本プロジェクトはMySQLを前提とする([01_requirements.md](01_requirements.md) 13章)ため、この挙動に依拠する。

## 1. users

| カラム | Migration型 | NULL | デフォルト | 制約・インデックス | 説明 |
|---|---|---|---|---|---|
| id | `id()` (bigIncrements) | NOT NULL | - | PK | |
| name | `string(255)` | NOT NULL | - | | 氏名 |
| email | `string(255)` | NOT NULL | - | UNIQUE | ログインID |
| password | `string(255)` | NOT NULL | - | | ハッシュ化済みパスワード |
| role | `string(20)` | NOT NULL | - | | `employee` / `admin`(許容値は本ドキュメント冒頭参照) |
| created_at | `timestamp()` | NOT NULL | CURRENT_TIMESTAMP | `timestamps()` | |
| updated_at | `timestamp()` | NOT NULL | CURRENT_TIMESTAMP | `timestamps()` | |

- `email_verified_at`・`remember_token`は、MVPでメール認証・ログイン状態保持(Remember Me)機能を持たないため設けない。将来Laravel Breeze等の標準認証スキャフォールドを追加導入する場合は、別途migrationでカラムを追加する。
- ユーザー削除機能はMVP対象外のため、削除方針・SoftDeletesは設けない([04_er_diagram.md](04_er_diagram.md) 6章)。

## 2. expense_categories

| カラム | Migration型 | NULL | デフォルト | 制約・インデックス | 説明 |
|---|---|---|---|---|---|
| id | `id()` | NOT NULL | - | PK | |
| name | `string(50)` | NOT NULL | - | UNIQUE | カテゴリ名(交通費/宿泊費/交際費/消耗品費/その他) |
| created_at | `timestamp()` | NOT NULL | CURRENT_TIMESTAMP | `timestamps()` | |
| updated_at | `timestamp()` | NOT NULL | CURRENT_TIMESTAMP | `timestamps()` | |

- MVPではCRUD機能を持たず、Seederで初期データを投入する読み取り専用マスタとする。

## 3. expense_reports

| カラム | Migration型 | NULL | デフォルト | 制約・インデックス | 説明 |
|---|---|---|---|---|---|
| id | `id()` | NOT NULL | - | PK | |
| user_id | `foreignId()` | NOT NULL | - | FK → users.id | 申請者 |
| expense_category_id | `foreignId()` | NOT NULL | - | FK → expense_categories.id | 経費カテゴリ |
| expense_date | `date()` | NOT NULL | - | | 利用日 |
| amount | `unsignedInteger()` | NOT NULL | - | | 金額(円)。0より大きい整数であることはFormRequestで検証する |
| payee | `string(255)` | NOT NULL | - | | 支払先 |
| description | `text()` | NOT NULL | - | | 内容 |
| receipt_image_path | `string(255)` | NULLABLE | NULL | | 領収書画像の保存パス(任意) |
| status | `string(20)` | NOT NULL | `'draft'` | INDEX | ステータス(許容値は本ドキュメント冒頭参照) |
| rejection_reason | `text()` | NULLABLE | NULL | | 却下理由の直近値(更新規則は[04_er_diagram.md](04_er_diagram.md)参照) |
| created_at | `timestamp()` | NOT NULL | CURRENT_TIMESTAMP | `timestamps()` | |
| updated_at | `timestamp()` | NOT NULL | CURRENT_TIMESTAMP | `timestamps()` | |

### 外部キー削除方針

| FK | 参照先 | ON DELETE | 理由 |
|---|---|---|---|
| user_id | users.id | `restrict` | ユーザー削除機能はMVP対象外。誤ってDBから直接ユーザーを削除した場合に、申請データが道連れで消える(またはuser_idがNULLになる)事故を防ぐ |
| expense_category_id | expense_categories.id | `restrict` | カテゴリ削除機能はMVP対象外。参照中のカテゴリが誤って削除され、申請データの整合性が崩れることを防ぐ |

### インデックス方針

- `user_id` / `expense_category_id`: FKカラムのため`->constrained()`によりMySQL上で自動的にインデックスが付与される(「FKカラムのインデックスに関する共通方針」参照)。SC-02(自分の申請一覧)の申請者による絞り込みにもこの自動インデックスがそのまま利用できる。
- `status`: FKではない単純なカラムのため自動インデックスの対象外。SC-05(管理者の申請一覧)でステータスによる絞り込み(初期表示は提出済み)が常に発生するため、明示的に`->index()`を付与する。

## 4. approval_histories

| カラム | Migration型 | NULL | デフォルト | 制約・インデックス | 説明 |
|---|---|---|---|---|---|
| id | `id()` | NOT NULL | - | PK | |
| expense_report_id | `foreignId()` | NOT NULL | - | FK → expense_reports.id | 対象申請 |
| actor_id | `foreignId()` | NOT NULL | - | FK → users.id | 操作者(提出/再提出は申請者、承認/却下は管理者) |
| action | `string(20)` | NOT NULL | - | | `submitted` / `approved` / `rejected`(許容値は本ドキュメント冒頭参照。再提出も`submitted`として記録) |
| from_status | `string(20)` | NOT NULL | - | | 遷移前ステータス |
| to_status | `string(20)` | NOT NULL | - | | 遷移後ステータス |
| comment | `text()` | NULLABLE | NULL | | 却下理由コメント等。却下理由の履歴上の正本([04_er_diagram.md](04_er_diagram.md)参照) |
| created_at | `timestamp()->useCurrent()` | NOT NULL | CURRENT_TIMESTAMP | | 追記型ログのため`updated_at`は持たない(モデル側は`const UPDATED_AT = null;`) |

### 外部キー削除方針

| FK | 参照先 | ON DELETE | 理由 |
|---|---|---|---|
| expense_report_id | expense_reports.id | `cascade` | 履歴は申請に従属するデータであり、申請が存在しない状態での履歴単独保持に意味がないため。ただし現行仕様では下書き(履歴を持たない唯一の削除可能ステータス)以外は削除されないため、実際にCASCADEが発火するケースはMVP運用上ほぼ発生しない |
| actor_id | users.id | `restrict` | 監査証跡としての性質上、操作者情報を含む履歴行が消えることを防ぐ。ユーザー削除機能自体もMVP対象外 |

**Migration実装時の注意(actor_id)**: Laravelの`constrained()`は、カラム名から末尾の`_id`を除いた語を複数形化してテーブル名を推論する規約になっている(例: `user_id` → `users`)。`actor_id`にこの規約をそのまま適用すると存在しない`actors`テーブルが推論されてしまうため、Migration実装時は`$table->foreignId('actor_id')->constrained('users')`のように参照先テーブルを明示的に指定する必要がある。

### インデックス方針

- `expense_report_id` / `actor_id`: いずれもFKカラムのため`->constrained()`によりMySQL上で自動的にインデックスが付与される(「FKカラムのインデックスに関する共通方針」参照)。明示的な`->index()`は不要。
- 現時点でMVPの画面・機能一覧に「特定の申請の履歴一覧」「特定ユーザーの操作履歴一覧」を表示する機能は定義されていないため、上記2カラム以外に絞り込み用の追加インデックスを設ける積極的な理由はない。将来、履歴閲覧機能を追加する場合はクエリパターンに応じて改めて検討する。

## 5. Seederで投入する初期データ

- `expense_categories`: 交通費・宿泊費・交際費・消耗品費・その他(5件)。
- `users`: 開発・動作確認用に一般社員1件以上・管理者1件以上を想定するが、具体的な人数・属性は実装フェーズで決定する。
