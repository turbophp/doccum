{{--
    Overrides the framework's default 503 view, which hardcodes "Service
    Unavailable" and never surfaces the exception message. The runtime config
    guard in RequireInstanceSetup depends on that message reaching the operator:
    it is the only way they learn to restore APP_KEY rather than assume the
    instance is merely down.

    The message is rendered ONLY for that case. Laravel hides abort() messages
    by default for a good reason, and rendering any 503 message would turn every
    future abort(503, $something) in this application into public output.
--}}
@extends('errors::minimal')

@section('title', __('Service Unavailable'))
@section('code', '503')
@section('message')
    @if (App\Providers\RuntimeConfigServiceProvider::hasError())
        {{ App\Providers\RuntimeConfigServiceProvider::error() }}
    @else
        {{ __('Service Unavailable') }}
    @endif
@endsection
