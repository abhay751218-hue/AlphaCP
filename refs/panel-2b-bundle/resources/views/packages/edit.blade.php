@php
    $isNew = ! $package->exists;
@endphp
@extends('layouts.panel')

@section('title', $isNew ? 'New package' : 'Edit '.$package->name)
@section('subtitle', 'Limits: -1 = unlimited')

@section('actions')
    <a class="btn small secondary" href="{{ route('packages.index') }}">← Packages</a>
@endsection

@section('content')
<div class="card">
    <form method="post" action="{{ $isNew ? route('packages.store') : route('packages.update', $package) }}">
        @csrf
        @unless ($isNew)
            @method('PUT')
        @endunless

        <div class="grid cols-2">
            <div>
                <label for="name">Name</label>
                <input id="name" name="name" required maxlength="100" value="{{ old('name', $package->name) }}" autocapitalize="none">

                <label for="description">Description</label>
                <input id="description" name="description" maxlength="255" value="{{ old('description', $package->description) }}">

                <label for="feature_list_id">Feature list</label>
                <select id="feature_list_id" name="feature_list_id">
                    <option value="">—</option>
                    @foreach ($lists as $list)
                        <option value="{{ $list->id }}" @selected(old('feature_list_id', $package->feature_list_id) == $list->id)>{{ $list->name }}</option>
                    @endforeach
                </select>

                <label for="status">Status</label>
                <select id="status" name="status" required>
                    @foreach (['active', 'archived'] as $st)
                        <option value="{{ $st }}" @selected(old('status', $package->status) === $st)>{{ $st }}</option>
                    @endforeach
                </select>

                <label class="mt"><input type="checkbox" name="is_default" value="1" @checked(old('is_default', $package->is_default))> Default package</label>
                <label><input type="checkbox" name="HASSHELL" value="1" @checked(old('HASSHELL', $package->HASSHELL))> Shell access (HASSHELL)</label>
                <label><input type="checkbox" name="DEDICATEDIP" value="1" @checked(old('DEDICATEDIP', $package->DEDICATEDIP))> Dedicated IP</label>
            </div>
            <div>
                <p class="help">cPanel-compatible keys. New accounts are created with these limits.</p>
                @foreach (\App\Support\PackageLimits::LABELS as $key => $label)
                    <label for="{{ $key }}">{{ $label }} <span class="mono">({{ $key }})</span></label>
                    <input id="{{ $key }}" name="{{ $key }}" type="number" required min="-1"
                           value="{{ old($key, $package->{$key} ?? \App\Support\PackageLimits::DEFAULTS[$key]) }}">
                @endforeach
            </div>
        </div>

        <button class="btn mt" type="submit">{{ $isNew ? 'Package banao' : 'Save' }}</button>
        @unless ($isNew)
            @can('packages.manage')
                @if (! $package->is_default)
                    <button class="btn danger mt" type="submit" form="archive-form">Archive</button>
                @endif
            @endcan
        @endunless
    </form>
    @unless ($isNew)
        <form id="archive-form" method="post" action="{{ route('packages.archive', $package) }}">@csrf</form>
    @endunless
</div>
@endsection
