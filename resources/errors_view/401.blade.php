@extends('tooL_errors::error_base')

@section("desc", $exception->getMessage())

@section("error_msg","当一个401错误发生的时候,意味着您没有权限访问该页面或者资源.")

