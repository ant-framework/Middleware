<?php
namespace Ant\Middleware;

use Closure;
use Generator;
use Exception;
use InvalidArgumentException;

/**
 * Todo Server版
 * Todo 尝试使用Context代替中间件传输参数
 * Todo 中间件处理完异常后,继续回调剩下来的栈
 *
 * Class Pipeline
 * @package Ant\Middleware
 */
class Pipeline
{
    /**
     * 默认加载的中间件
     *
     * @var array
     */
    protected $nodes = [];

    /**
     * 执行时传递给每个中间件的参数
     *
     * @var array
     */
    protected $arguments = [];

    /**
     * 7.0之前使用第二次协同返回数据,7.0之后通过getReturn返回数据
     *
     * @var bool
     */
    protected $isPhp7 = false;

    /**
     * Middleware constructor.
     */
    public function __construct()
    {
        $this->isPhp7 = version_compare(PHP_VERSION, '7.0.0', '>=');
    }

    /**
     * 添加一个中间件到顶部
     *
     * @param callable $callback
     * @return $this
     */
    public function unShift(callable $callback)
    {
        array_unshift($this->nodes,$callback);

        return $this;
    }

    /**
     * 添加一个中间件到尾部
     *
     * @param callable $callback
     * @return $this
     */
    public function push(callable $callback)
    {
        array_push($this->nodes,$callback);

        return $this;
    }

    /**
     * 设置在中间件中传输的参数
     *
     * @return self $this
     */
    public function send()
    {
        $this->arguments = func_get_args();

        return $this;
    }

    /**
     * 设置经过的中间件
     *
     * @param array|callable $nodes 经过的每个节点
     * @return $this
     */
    public function through($nodes)
    {
        $nodes = is_array($nodes) ? $nodes : func_get_args();

        foreach ($nodes as $node) {
            if (!is_callable($node)) {
                throw new InvalidArgumentException('Pipeline must be a callback');
            }
        }

        $this->nodes = $nodes;

        return $this;
    }

    /**
     * 设定中间件运行终点,并执行
     *
     * @param callable $destination
     * @return mixed
     * @throws Exception
     */
    public function then(callable $destination)
    {
        // 初始化参数
        $stack = [];
        $arguments = $this->arguments;

        try {
            foreach ($this->nodes as $node) {
                $generator = call_user_func_array($node, $arguments);

                if ($generator instanceof Generator) {
                    // 将协同函数添加到函数栈
                    $stack[] = $generator;

                    $yieldValue = $generator->current();
                    if ($yieldValue === false) {
                        // 打断中间件执行流程
                        $status = false;
                        break;
                    } elseif ($yieldValue instanceof Arguments) {
                        // 替换传递参数
                        $arguments = $yieldValue->toArray();
                    }
                }
            }

            $result = !isset($status) || $status !== false
                ? call_user_func_array($destination, $arguments)
                : null;

            // 回调函数栈
            while ($generator = array_pop($stack)) {
                $generator->send($result);
                // 尝试用协同返回数据进行替换,如果无返回则继续使用之前结果
                $result = $this->getResult($generator);
            }
        } catch (Exception $exception) {
            $tryCatch = $this->exceptionHandle($stack, function ($e) {
                // 如果无法处理,交给上层应用处理
                throw $e;
            });

            $result = $tryCatch($exception);
        }

        return $result;
    }

    /**
     * 处理异常
     *
     * @param $stack
     * @param $throw
     * @return Closure
     */
    protected function exceptionHandle($stack, $throw)
    {
        // 出现异常之后开始回调中间件函数栈
        // 如果内层中间件无法处理异常
        // 那么外层中间件会尝试捕获这个异常
        // 如果一直无法处理,异常将会抛到最顶层来处理
        // 如果处理了这个异常,那么异常回调链将会被打断
        return array_reduce($stack, function (Closure $stack, Generator $generator) {
            return function (Exception $exception) use ($stack, $generator) {
                try {
                    // 将异常交给内层中间件
                    $generator->throw($exception);
                    // 异常处理成功,将结果返回给应用程序
                    return $this->getResult($generator);
                } catch (Exception $e) {
                    // 将异常交给外层中间件
                    return $stack($e);
                }
            };
        },$throw);
    }

    /**
     * 获取协程函数返回的数据,php7获取return数据,php7以下使用第二次yield
     *
     * @param Generator $generator
     * @return mixed
     */
    protected function getResult(Generator $generator)
    {
        return $this->isPhp7
            ? $generator->getReturn()
            : $generator->current();
    }
}