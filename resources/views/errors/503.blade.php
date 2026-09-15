{{--
    Overrides the framework's default 503 view, which hardcodes "Service
    Unavailable" and never surfaces the exception message. The runtime config
    guard in RequireInstanceSetup relies on that message reaching the operator
    -- it is the only way they learn to check APP_KEY rather than assume the
    instance is simply down.
--}}
@extends('errors::minimal')

@section('title', __('Service Unavailable'))
@section('code', '503')
@section('message')
    {{ $exception->getMessage() !== '' ? $exception->getMessage() : __('Service Unavailable') }}
@endsection
