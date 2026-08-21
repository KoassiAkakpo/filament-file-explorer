<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Contracts;

/**
 * Marks every event the explorer fires.
 *
 * It is an interface rather than a shared base class because Laravel's
 * dispatcher resolves listeners for the interfaces an event implements
 * (Dispatcher::addInterfaceListeners) but not for its parent classes. One
 * listener registered against this can therefore see everything that happens
 * in the explorer — which is what an audit trail needs — while a listener
 * registered against a concrete event still hears only that one.
 *
 * Nothing is declared on it on purpose: the shared payload lives on
 * Events\ExplorerEvent as readonly properties, and a marker cannot go stale.
 */
interface FileExplorerEvent {}
