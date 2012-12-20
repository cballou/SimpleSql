## About ##
SimpleSql is a wrapper around PHP's PDO and is intended to be an easy to use drop-in for your projects. It abstracts away
the atrocities of querying by using common methods while still giving you full control of your queries.

## Querying and Prepared Statements ##
One of the primary benefits of using PDO is you get out of box support of prepared statements. This really boils down
to speed and security (prevention of SQL injection). Other than the `query()` method, all other methods utilize
prepared statements. These methods include: `fetch_row()`, `fetch_rows()`, `insert()`, `update()`, `delete()`.

Below you will find two examples of how you use prepared statements:

#### Named Bindings ####
This method is ideal because it is easily changeable if something changes in your code. You specify bound parameters
to be replaced using the `:parameter` syntax in your SQL string. Your data array must be an associative array of
key/val pairs where the keys match your named parameters in the SQL.

```php
<?php
$data = array(':firstname' => 'Jack', ':lastname' => 'Daniels');
$sql = 'INSERT INTO user SET firstname = :firstname, lastname = :lastname';
```

#### Placeholder Bindings ####
This method is not preferred but very easy to understand. It assumes that your SQL contains a question mark, `?`,
wherever you expect a variable. This essentially handles the same as `vsprintf("%s %s")`, where there is a correlation
in the order of your placeholders, `?`, to the order of the variables in your passed in `$data` array. Things
must be in order.

```php
<?php
$data = array('Jack', 'Daniels');
$sql = 'INSERT INTO user SET firstname = ?, lastname = ?';
```


## Public Methods ##

#### `__construct($host, $username, $password, $database)` ####
The default constructor for instantiation, i.e.

```php
<?php
$ss = new SimpleSql('localhost', 'root', 'pass', 'myDb');
```

#### `connect($host, $username, $password, $database, $driver = 'mysql')` ####
A simple method allowing you to connect to a database. Under the hood, this
simply makes a call to `reconnect()`, which is one and the same. The default
constructor initially makes a call to `connect()`.

#### `query($sql, $fetch_mode = PDO::FETCH_ASSOC)` ####
Standard PDO method for querying. Assumes the user has escaped
everything themselves via `quote()`, otherwise entirely insecure.
You should instead by using a prepared statement method listed above.
This method is not recommended but provided for convenience to
knowledgeable individuals. You can override the fetch mode to return an object
by setting `$fetch_mode = PDO::FETCH_OBJ`.



#### `fetchRow($sql, $data = NULL, $fetch_mode = PDO::FETCH_ASSOC)` ####
For fetching multiple rows. SimpleSql won't inherently return an array
as that would entail a huge performance hit. You will be returned

```php
<?php
// simple row fetch without any parameter bindings
$row = $db->fetchRow('SELECT * FROM users WHERE id = 1');
if (!empty($row)) {
    var_dump($row);
}

```

#### `fetchRows($sql, $data = NULL, $fetch_mode = PDO::FETCH_ASSOC)` ####
For fetching multiple rows. SimpleSql won't inherently return an array
as that would entail a huge performance hit for large datasets. You are
returned the `PDOStatement` object or `FALSE` on failure. To iterate over
the result set, you may do one of the following:

```php
<?php
// the preferred method is to use foreach, as the PDOStatement class implements the Traversible interface
$stmt = $db->fetchRows('SELECT * FROM users');
foreach ($stmt as $row) {
    // depending on your $fetch_mode, you may have an associative array, numerically indexed array, object, or both
    echo print_r($row, true);
}
```

```php
<?php
// using while, not preferred as it's slower than foreach
$stmt = $db->fetchRows('SELECT * FROM users WHERE id = 1');
while ($row = $stmt->fetch()) {
    // depending on your $fetch_mode, you may have an associative array, numerically indexed array, object, or both
    echo print_r($row, true);
}
```

#### `insert($table, $data)` ####
This is really a glorified wrapper around `execute()` where we create the sql
for you. It's useful for simple inserts. It does no verification that the data
matches table columns. The benefit of `insert()` is that it will return the
`lastInsertId` value for you on success and `FALSE` on failure.

#### `update($table, $where, $data)` ####
This is really a glorified wrapper around `execute()` where we create the
sql for you. It's only useful in simple update cases as you can't do anything
crazy. If you need to get crazy, use `execute()`.

#### `delete($table, $where)` ####
This is really a glorified wrapper around `execute()` where we create the
sql for you. If you need to get crazy, use `execute()`. We leave it up to you
in terms of how to handle the return via `count()`.

#### `count()` ####
Returns the number of rows affected by the last DELETE, INSERT, or UPDATE.
If the last SQL statement executed by the associated PDOStatement was a
SELECT statement, some databases may return the number of rows returned
by that statement. However, this behaviour is not guaranteed for all
databases and should not be relied on for portable applications. Considering
the base case is MySQL, this will correctly return the number of rows found
in your SELECT statement.

#### `quote()` ####
Quote is only intended to be used in combination with `query()`, which should
really be never. They go hand in hand, as `quote()` is the only true means of
preventing SQL injection attacks with `query()`.

#### `beginTransaction()` ####
For starting an InnoDB transaction.

#### `commit()` ####
For committing an InnoDB transaction.

#### `rollback()` ####
For rolling back an InnoDB transaction, generally used if an error was encountered.

#### `startTransaction()` ####
A wrapper for `beginTransaction()`, just to be nice.

#### `endTransaction()` ####
A wrapper for `endTransaction()`, just to be nice.

#### `close()` ####
To force close the PDO connection. Generally you will not need to do this as
it's automatically done once the script ends. For more information, read
[PHP: PDO Connections & Connection Management](http://us.php.net/manual/en/pdo.connections.php)
