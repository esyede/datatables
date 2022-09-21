<?php

namespace Esyede;

defined('DS') or exit('No direct script access.');

use System\Arr;
use System\Str;
use System\Input;
use System\Config;
use System\Database;
use System\Response;
use System\Database\Expression;
use System\Database\Facile\Model;

class Datatables
{
    public $query;
    public $columns = [];
    public $ordered = [];

    protected $driver;
    protected $added = [];
    protected $removed = [];
    protected $edited = [];
    protected $filterings = [];
    protected $collections;
    protected $records = [];
    protected $data = [];
    protected $input = [];
    protected $use_column_data;
    protected $index;
    protected $row_classes;
    protected $row_data = [];
    protected $total = 0;
    protected $filtered = 0;

    public static function of($query, $use_column_data = false)
    {
        $instance = new static();
        $instance->use_column_data = $use_column_data
            ? $use_column_data
            : Config::get('datatables::main.use_column_data', false);
        $instance->process($query);

        return $instance;
    }

    public function add($name, \Closure $content, $order = false)
    {
        $this->added[] = compact('name', 'content', 'order');
        return $this;
    }

    public function edit($name, \Closure $content)
    {
        $this->edited[] = compact('name', 'content');
        return $this;
    }

    public function forget($columns = [])
    {
        $columns = is_array($columns) ? $columns : func_get_args();
        $this->removed = array_merge($this->removed, $columns);

        return $this;
    }

    public function filter($column, $method)
    {
        $parameters = func_get_args();
        $parameters = array_splice($parameters, 2);
        $this->filterings[$column] = compact('method', 'parameters');

        return $this;
    }

    public function index($name)
    {
        $this->index = $name;
        return $this;
    }

    public function row_class($css_class)
    {
        $this->row_classes = $css_class;
        return $this;
    }

    public function row_data($name, $data)
    {
        $this->row_data[$name] = $data;
        return $this;
    }

    public function make($raw = false)
    {
        $this->ordered();
        $this->prepares();
        $this->results();
        $this->modify();
        $this->regulates();

        return $this->output($raw);
    }

    protected function results()
    {
        if ($this->driver === 'facile') {
            $this->collections = $this->query->get();
            $this->records = $this->collections->to_array();
        } else {
            $this->collections = $this->query->get();
            $this->records = array_map(function ($object) {
                return (array) $object;
            }, $this->collections);
        }

        if ($this->use_column_data) {
            $walk = function ($value, $key, $prefix = null) use (&$walk, &$records) {
                $key = is_null($prefix) ? $key : $prefix.'.'.$key;

                if (is_array($value)) {
                    array_walk($value, $walk, $key);
                } else {
                    $records = Arr::add($records, $key, $value);
                }
            };

            $records = [];
            array_walk($this->records, $walk);
            $this->records = $records;
        }
    }

    protected function prepares()
    {
        $this->counts('total');
        $this->filtering();
        $this->counts('filtered');
        $this->paging();
        $this->ordering();
    }

    protected function process($query)
    {
        $this->query = $query;
        $this->driver = ($query instanceof Model) ? 'facile' : 'magic';
        $connection = Config::get('database.default');

        if ($this->use_column_data) {
            if ($this->driver === 'facile') {
                $this->columns = array_map(function ($column) {
                    return trim(Database::connection($connection)->pdo()->quote($column['data']), "'");
                }, Input::get('columns', []));
            } else {
                $this->columns = ($this->driver === 'facile')
                    ? $this->query->table->selects
                    : $this->query->selects;
                $this->columns = Arr::wrap($this->columns);
            }
        } else {
            $this->columns = ($this->driver === 'facile')
                ? $this->query->table->selects
                : $this->query->selects;
        }
    }

    protected function modify()
    {
        foreach ($this->records as $name => &$data) {
            foreach ($this->added as $key => $value) {
                $value['content'] = $this->content($value['content'], $data, $this->collections[$name]);

                if ($this->use_column_data) {
                    Arr::set($data, $value['name'], $value['content']);
                } else {
                    $data = $this->includes($value, $data);
                }
            }

            foreach ($this->edited as $key => $value) {
                $value['content'] = $this->content($value['content'], $data, $this->collections[$name]);

                if ($this->use_column_data) {
                    Arr::set($data, $value['name'], $value['content']);
                } else {
                    $data[$value['name']] = $value['content'];
                }
            }
        }
    }

    protected function regulates()
    {
        foreach ($this->records as $key => $value) {
            foreach ($this->removed as $column) {
                if ($this->use_column_data) {
                    Arr::forget($value, $column);
                } else {
                    unset($value[$column]);
                }
            }

            $row = $this->use_column_data ? $value : array_values($value);

            if ($this->index !== null) {
                $row['DT_RowId'] = array_key_exists($this->index, $value)
                    ? $value[$this->index]
                    : $this->content($this->index, $value, $this->collections[$key]);
            }

            if ($this->row_classes !== null) {
                $row['DT_RowClass'] = $this->content($this->row_classes, $value, $this->collections[$key]);
            }

            if (count($this->row_data)) {
                $row['DT_RowData'] = [];

                foreach ($this->row_data as $tkey => $tvalue) {
                    $row['DT_RowData'][$tkey] = $this->content($tvalue, $value, $this->collections[$key]);
                }
            }

            $this->data[] = $row;
        }
    }

    private function inject(&$parameters, $value)
    {
        if (is_array($parameters)) {
            foreach ($parameters as $key => $param) {
                $parameters[$key] = $this->inject($param, $value);
            }
        } elseif ($parameters instanceof Expression) {
            $parameters = Database::raw(str_replace('$1', $value, $parameters));
        } elseif (is_callable($parameters)) {
            $parameters = $parameters($value);
        } elseif (is_string($parameters)) {
            $parameters = str_replace('$1', $value, $parameters);
        }

        return $parameters;
    }

    protected function ordered()
    {
        $added = [];
        $ordered = [];
        $count = 0;

        foreach ($this->added as $key => $value) {
            if ($value['order'] === false) {
                continue;
            }

            $added[] = $value['order'];
        }

        $length = count($this->columns);

        for ($i = 0; $i < $length; $i++) {
            if (in_array($this->column($this->columns[$i]), $this->removed)) {
                continue;
            }

            if (in_array($count, $added)) {
                $count++;
                $i--;
                continue;
            }

            preg_match('/\s+as\s+(\S*?)$/si', $this->columns[$i], $matches);
            $ordered[$count] = empty($matches) ? $this->columns[$i] : $matches[1];
            $count++;
        }

        $this->ordered = $ordered;
    }

    protected function content($content, $data = null, $param = null)
    {
        return ($content instanceof \Closure) ? $content($param) : $content;
    }

    protected function includes(array $items, array $values)
    {
        if ($items['order'] === false) {
            return array_merge($values, [$items['name'] => $items['content']]);
        }

        $total = 0;
        $last = $values;
        $first = [];

        if (count($values) <= $items['order']) {
            return $values + [$items['name'] => $items['content']];
        }

        foreach ($values as $key => $value) {
            if ($total === (int) $items['order']) {
                return array_merge($first, [$items['name'] => $items['content']], $last);
            }

            unset($last[$key]);
            $first[$key] = $value;
            $total++;
        }
    }

    protected function paging()
    {
        $start = Input::get('start');
        $length = Input::get('length');

        if (is_numeric($start) && is_numeric($length) && (int) $length !== -1) {
            $this->query->skip((int) $start)->take((int) (($length > 0) ? $length : 10));
        }
    }

    protected function ordering()
    {
        $order = (array) Input::get('order', []);
        $length = count($order);

        if (is_array($order) && $length > 0) {
            $columns = $this->cleans($this->ordered);

            for ($i = 0; $i < $length; $i++) {
                $order = (int) Input::get('order.'.$i.'.column', 0);

                if (isset($columns[$order])) {
                    if ((string) Input::get('columns.'.$order.'.orderable') === 'true') {
                        $this->query->order_by($columns[$order], Input::get('order.'.$i.'.dir', 'asc'));
                    }
                }
            }
        }
    }

    protected function cleans($columns, $use_alias = true)
    {
        $results = [];

        foreach ($columns as $key => $value) {
            preg_match('/^(.*?)\s+as\s+(\S*?)\s*$/si', $value, $matches);
            $results[$key] = empty($matches) ? ($use_alias ? $this->column($value) : $value) : $matches[$use_alias ? 2 : 1];
        }

        return $results;
    }

    protected function filtering()
    {
        $columns = $this->columns;
        $length = count($columns);

        for ($i = 0; $i < $length; $i++) {
            if (in_array($this->column($columns[$i]), $this->removed)) {
                unset($columns[$i]);
            }
        }

        $columns = array_values($columns);
        $names = $this->cleans($columns, false);
        $aliases = $this->cleans($columns, ! $this->use_column_data);
        $keyword = (string) Input::get('search.value', '');
        $total = count((array) Input::get('columns', []));

        if ($keyword !== '') {
            $self = $this;
            $this->query->where(function ($query) use (&$self, $aliases, $names, $keyword, $total) {
                for ($i = 0; $i < $total; $i++) {
                    if (isset($aliases[$i]) && (string) Input::get('columns.'.$i.'.searchable') === 'true') {
                        if (isset($self->filterings[$aliases[$i]])) {
                            $filter = $self->filterings[$aliases[$i]];
                            $method = 'or_'.ucfirst($filter['method']);
                            $class = ($self->driver === 'facile') ? $query->query() : $query->query;

                            if (method_exists($class, $method)
                            && count($filter['parameters']) <= count((new \ReflectionMethod($class, $method))->bindings)) {
                                if (isset($filter['parameters'][1]) && Str::upper(trim($filter['parameters'][1])) === 'LIKE') {
                                    $keyword = $self->keyword($keyword);
                                }

                                call_user_func_array([$query, $method], $self->inject($filter['parameters'], $keyword));
                            }
                        } else {
                            $keyword = $self->keyword($keyword);
                            $begin = null;
                            $end = null;

                            if (Config::get('database.default') === 'pgsql') {
                                $begin = 'CAST(';
                                $end = ' as TEXT)';
                            }

                            $column = $this->name($names[$i]);

                            if (Config::get('datatables::main.case_insensitive', false)) {
                                $query->or_where(Database::raw('LOWER('.$begin.$column.$end.')'), 'LIKE', Str::lower($keyword));
                            } else {
                                $query->or_where(Database::raw($begin.$column.$end), 'LIKE', $keyword);
                            }
                        }
                    }
                }
            });
        }

        for ($i = 0; $i < $total; $i++) {
            if (isset($aliases[$i])
            && (string) Input::get('columns.'.$i.'.searchable') === 'true'
            && (string) Input::get('columns.'.$i.'.search.value') !== '') {
                if (isset($this->filterings[$aliases[$i]])) {
                    $filter = $this->filterings[$aliases[$i]];
                    $keyword = Input::get('columns.'.$i.'.search.value');

                    if (isset($filter['parameters'][1]) && Str::upper(trim($filter['parameters'][1])) === 'LIKE') {
                        $keyword = $this->keyword($keyword);
                    }

                    call_user_func_array([$this->query, $filter['method']], $this->inject($filter['parameters'], $keyword));
                } else {
                    $keyword = $this->keyword(Input::get('columns.'.$i.'.search.value'));
                    $column = $this->name($names[$i]);

                    if (Config::get('datatables::main.case_insensitive', false)) {
                        $this->query->where(Database::raw('LOWER('.$column.')'), 'LIKE', Str::lower($keyword));
                    } else {
                        $column = strstr($names[$i], '(') ? Database::raw($column) : $column;
                        $this->query->where($column, 'LIKE', $keyword);
                    }
                }
            }
        }
    }

    public function keyword($value)
    {
        if (mb_strpos($value, '%') !== false) {
            return $value;
        }

        if (Config::get('datatables::main.use_wildcards', false)) {
            return '%'.$this->wildcard($value).'%';
        }

        return '%'.trim($value).'%';
    }

    public function wildcard($keyword, $lowercase = true)
    {
        return preg_replace('\s+', '%', $lowercase ? Str::lower($keyword) : $keyword);
    }

    public function prefix()
    {
        return Config::get('database.connections.'.Config::get('database.default').'.prefix', '');
    }

    protected function name($column)
    {
        $tables = $this->tables();
        $prefix = array_filter($tables, function ($value) use (&$column) {
            return mb_strpos($column, $value.'.') === 0;
        });

        return (count($prefix) > 0) ? $this->prefix().$column : $column;
    }

    protected function tables()
    {
        $names = [];
        $query = ($this->driver === 'facile') ? $this->query->query() : $this->query;

        $names[] = $query->from;
        $joins = $query->joins ? $query->joins : [];
        $prefix = $this->prefix();

        foreach ($joins as $join) {
            $table = preg_split('/ as /i', $join->table);
            $names[] = $table[0];

            if (isset($table[1]) && $prefix && mb_strpos($table[1], $prefix) === 0) {
                $names[] = preg_replace('/^'.$prefix.'/', '', $table[1]);
            }
        }

        return $names;
    }

    protected function column($column)
    {
        preg_match('/^(\S*?)\s+as\s+(\S*?)$/si', $column, $matches);

        if (! empty($matches)) {
            return $matches[2];
        } elseif (mb_strpos($column, '.')) {
            $items = explode('.', $column);
            return array_pop($items);
        }

        return $column;
    }

    protected function counts($type = 'total')
    {
        $cloned = clone $this->query;

        if (! preg_match('/UNION/i', $cloned->to_sql())) {
            $cloned->select(Database::raw("'1' as row"));

            if ($cloned->havings) {
                foreach ($cloned->havings as $having) {
                    if (isset($having['column'])) {
                        $cloned->selects = $having['column'];
                    } else {
                        $found = false;

                        foreach ($this->filterings as $column => $filter) {
                            if ($filter['parameters'][0] === $having['sql']) {
                                $found = $column;
                                break;
                            }
                        }

                        if ($found !== false) {
                            foreach ($this->columns as $col) {
                                $names = preg_split('/ as /i', $col);

                                if (isset($columns[1]) && $names[1] === $found) {
                                    $found = $names[0];
                                    break;
                                }
                            }

                            $cloned->selects = $found;
                        }
                    }
                }
            }
        }

        $cloned->orderings = null;

        if (! $type === 'total' || $type === 'filtered') {
            $bindings = array_map(function ($binding) {
                return Database::escape($binding);
            }, $cloned->bindings);
            $sql = Str::replace_array('?', $bindings, '('.$cloned->to_sql().') AS count_row_table');
            $this->{$type} = $this->query->connection->table(Database::raw($sql))->count();
        }
    }

    protected function output($raw = false)
    {
        $records = [
            'draw' => (int) Input::get('draw'),
            'recordsTotal' => $this->total,
            'recordsFiltered' => $this->filtered,
            'data' => $this->data,
        ];

        return $raw ? $records : Response::json($records);
    }
}
