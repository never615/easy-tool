@extends('tooL_errors::error_base')

@section("desc", $exception->getMessage())

@section("error_msg","When a 400 error occurs, it means that your request parameters are wrong.")

